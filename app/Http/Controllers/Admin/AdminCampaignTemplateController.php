<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignTemplate;
use App\Services\CampaignBlockRenderer;
use App\Services\CampaignMergeTags;
use App\Support\CampaignStarterTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campaign design: the block catalogue, the built-in starting points, and the
 * marketing team's own saved designs.
 *
 * The catalogue endpoint exists so the editor UI is generated from the backend's
 * block definitions rather than a hardcoded copy in the frontend — a new block
 * type becomes available in the editor the moment it's added to
 * CampaignBlockRenderer::BLOCKS, the same way markets are auto-discovered rather
 * than listed.
 */
class AdminCampaignTemplateController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/campaign-design — marketing.manage
    //
    // Everything the editor needs to render itself: block types with their
    // fields, theme presets, and the merge tags available for personalization.
    // -------------------------------------------------------------------------
    public function design(): JsonResponse
    {
        $blocks = [];
        foreach (CampaignBlockRenderer::BLOCKS as $type => $spec) {
            $blocks[] = [
                'type'        => $type,
                'label'       => $spec['label'],
                'description' => $spec['description'],
                'fields'      => $this->fieldList($spec['fields']),
            ];
        }

        $themes = [];
        foreach (CampaignBlockRenderer::THEMES as $preset => $values) {
            $themes[] = array_merge(['preset' => $preset], $values);
        }

        $mergeTags = [];
        foreach (CampaignMergeTags::TAGS as $tag => [$label, $description, $sample]) {
            $mergeTags[] = [
                'tag'         => '[[' . $tag . ']]',
                'label'       => $label,
                'description' => $description,
                'sample'      => $sample,
                // Every tag takes a fallback for contacts missing that field.
                'with_fallback' => '[[' . $tag . '|your fallback]]',
            ];
        }

        return response()->json([
            'data' => [
                'blocks'        => $blocks,
                'themes'        => $themes,
                'default_theme' => CampaignBlockRenderer::DEFAULT_THEME,
                'merge_tags'    => $mergeTags,
                'inline_formatting' => [
                    ['syntax' => '**bold**', 'renders' => 'bold'],
                    ['syntax' => '*italic*', 'renders' => 'italic'],
                    ['syntax' => '[click here](https://okelcor.com)', 'renders' => 'a link'],
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/campaign-templates/starters — marketing.manage
    //
    // Built-in designs, always available. Read-only: "use" one by copying its
    // blocks into a new campaign client-side, or save it as your own template.
    // -------------------------------------------------------------------------
    public function starters(CampaignBlockRenderer $renderer): JsonResponse
    {
        // Rendered here for the same reason the saved templates are: "use this
        // design" must show what will actually be sent, not the client's own
        // approximation of it.
        $starters = array_map(function (array $starter) use ($renderer) {
            $starter['preview_html'] = $renderer->render($starter['blocks'], $starter['theme'] ?? []);

            return $starter;
        }, CampaignStarterTemplates::all());

        return response()->json(['data' => $starters]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/campaign-templates — marketing.manage
    // -------------------------------------------------------------------------
    public function index(): JsonResponse
    {
        $templates = CampaignTemplate::with('creator:id,name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn ($t) => $this->format($t))->values(),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/campaign-templates/{id} — marketing.manage
    // -------------------------------------------------------------------------
    public function show(int $id, CampaignBlockRenderer $renderer): JsonResponse
    {
        $template = CampaignTemplate::with('creator:id,name')->findOrFail($id);

        return response()->json(['data' => $this->format($template, withBlocks: true, renderer: $renderer)]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/campaign-templates — marketing.manage
    // -------------------------------------------------------------------------
    public function store(Request $request, CampaignBlockRenderer $renderer): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'blocks'      => ['required', 'array'],
            'theme'       => ['nullable', 'array'],
        ]);

        if ($errors = $renderer->validateBlocks($data['blocks'])) {
            return $this->blockErrors($errors);
        }

        $template = CampaignTemplate::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'blocks'      => $data['blocks'],
            'theme'       => $data['theme'] ?? ['preset' => CampaignBlockRenderer::DEFAULT_THEME],
            'created_by'  => $request->user()->id,
        ]);

        return response()->json([
            'data'    => $this->format($template, withBlocks: true),
            'message' => 'Template saved.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/campaign-templates/{id} — marketing.manage
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id, CampaignBlockRenderer $renderer): JsonResponse
    {
        $template = CampaignTemplate::findOrFail($id);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'blocks'      => ['sometimes', 'array'],
            'theme'       => ['sometimes', 'nullable', 'array'],
        ]);

        if (isset($data['blocks']) && ($errors = $renderer->validateBlocks($data['blocks']))) {
            return $this->blockErrors($errors);
        }

        $template->update($data);

        return response()->json([
            'data'    => $this->format($template->fresh(), withBlocks: true),
            'message' => 'Template updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/campaign-templates/{id} — marketing.manage
    // -------------------------------------------------------------------------
    public function destroy(int $id): JsonResponse
    {
        CampaignTemplate::findOrFail($id)->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    // -------------------------------------------------------------------------

    /**
     * Flattens a field map into the `[{name, type, …}]` list the editor reads.
     *
     * Applied to a `group_list`'s `item_fields` as well as to a block's own
     * fields — those were being served as an object keyed by field name while
     * the outer fields were a list, so the same concept arrived in two shapes
     * and a renderer for one could not be reused for the other. Frontend hit
     * exactly that and stopped, correctly: `group_list` is a container whose
     * leaves are field types they already draw, and now it looks like it.
     *
     * Recursive because nothing prevents a group inside a group, and a shape
     * that only holds one level deep is a shape that breaks the first time it
     * is nested.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function fieldList(array $fields): array
    {
        $out = [];

        foreach ($fields as $name => $field) {
            if (isset($field['item_fields']) && is_array($field['item_fields'])) {
                $field['item_fields'] = $this->fieldList($field['item_fields']);
            }

            $out[] = array_merge(['name' => $name], $field);
        }

        return $out;
    }

    /**
     * Block problems come back as a plain list under `errors.blocks`, already
     * phrased for a non-technical user ("Block 3 (Button): …"), so the editor
     * can show them next to the block instead of translating a schema error.
     *
     * @param  array<int, string>  $errors
     */
    private function blockErrors(array $errors): JsonResponse
    {
        return response()->json([
            'message' => 'Some blocks need fixing before this can be saved.',
            'errors'  => ['blocks' => $errors],
            'code'    => 'invalid_blocks',
        ], 422);
    }

    private function format(CampaignTemplate $t, bool $withBlocks = false, ?CampaignBlockRenderer $renderer = null): array
    {
        $data = [
            'id'          => $t->id,
            'name'        => $t->name,
            'description' => $t->description,
            'theme'       => $t->theme,
            'block_count' => is_array($t->blocks) ? count($t->blocks) : 0,
            'created_by'  => $t->creator?->name,
            'created_at'  => $t->created_at?->toIso8601String(),
            'updated_at'  => $t->updated_at?->toIso8601String(),
        ];

        // The list endpoint stays small; the detail endpoint carries the design.
        if ($withBlocks) {
            $data['blocks'] = $t->blocks;
        }

        // …and the design as it will actually be sent.
        //
        // Reopening a saved template was showing something different from what
        // the same design showed when it was imported, because the two were
        // rendered by different code: the import returns HTML from this
        // renderer, while the editor was drawing the blocks itself and had no
        // rendering for the block types it did not know. A block type the
        // client has never heard of is normal and permanent — this is where new
        // ones arrive — so the preview cannot depend on the client knowing them.
        if ($withBlocks && $renderer !== null && is_array($t->blocks)) {
            $data['preview_html'] = $renderer->render($t->blocks, is_array($t->theme) ? $t->theme : []);
            $data['preview_text'] = $renderer->renderText($t->blocks);
        }

        return $data;
    }
}
