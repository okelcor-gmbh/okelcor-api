<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autosave for the campaign editor.
 *
 * Reported by a marketer: leaving the Mail Campaign tab for the Media Library
 * and coming back lost everything. Nothing persisted work in progress —
 * `POST /admin/bulk-emails` sends, it does not save.
 *
 * Two rules shape this controller, and both run against the usual instincts:
 *
 *  1. **Validation is deliberately permissive.** Blocks are NOT run through
 *     `CampaignBlockRenderer::validateBlocks()` here. A half-built Button
 *     block with no URL yet is exactly what autosave has to store — a save
 *     that rejects incomplete work is a save that refuses precisely when the
 *     marketer most needs it. Block rules are enforced at preview and at
 *     send, which is where an error can actually be acted on.
 *
 *  2. **A draft is private to its author.** Every query is scoped to the
 *     caller, and an id belonging to someone else returns 404 rather than
 *     403 — the same rule applied to partner sales, for the same reason: a
 *     403 would confirm the id exists.
 */
class AdminCampaignDraftController extends Controller
{
    /**
     * GET /api/v1/admin/campaign-drafts
     *
     * Light list for a "restore" picker — no blocks payload.
     */
    public function index(Request $request): JsonResponse
    {
        $drafts = CampaignDraft::where('admin_user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->limit(CampaignDraft::MAX_PER_AUTHOR)
            ->get();

        return response()->json([
            'data' => $drafts->map(fn (CampaignDraft $d) => $this->formatSummary($d))->values(),
        ]);
    }

    /**
     * GET /api/v1/admin/campaign-drafts/latest
     *
     * What the editor calls on load to offer "restore your unsaved work".
     * Returns `data: null` rather than 404 when there is nothing to restore,
     * so the editor does not have to treat a normal empty state as an error.
     */
    public function latest(Request $request): JsonResponse
    {
        $draft = CampaignDraft::where('admin_user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->first();

        // An editor that opened and autosaved a blank canvas should not
        // produce a restore prompt that restores nothing.
        if (! $draft || $draft->isEmpty()) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->format($draft)]);
    }

    /**
     * GET /api/v1/admin/campaign-drafts/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $draft = $this->findOwned($request, $id);

        if (! $draft) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->format($draft)]);
    }

    /**
     * POST /api/v1/admin/campaign-drafts
     *
     * Called once when the editor opens; the returned id is then reused for
     * every autosave, so a compose session produces one row rather than one
     * per keystroke.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateDraft($request);

        $draft = CampaignDraft::create($data + ['admin_user_id' => $request->user()->id]);

        CampaignDraft::pruneFor($request->user()->id);

        return response()->json([
            'data'    => $this->format($draft),
            'message' => 'Draft saved.',
        ], 201);
    }

    /**
     * PUT /api/v1/admin/campaign-drafts/{id}
     *
     * The autosave endpoint. A full replace of editor state, because the
     * editor holds the whole document — a partial merge would make deleting
     * the last block impossible to express.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $draft = $this->findOwned($request, $id);

        if (! $draft) {
            return $this->notFound();
        }

        $draft->fill($this->validateDraft($request))->save();

        return response()->json([
            'data'    => $this->format($draft),
            'message' => 'Draft saved.',
        ]);
    }

    /**
     * DELETE /api/v1/admin/campaign-drafts/{id}
     *
     * Returns 200 for an id that is already gone. Discard is fired on send
     * and on "start fresh", both of which can be retried after a dropped
     * connection, and an error on the second attempt would be noise about
     * something that is in the desired state.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwned($request, $id)?->delete();

        return response()->json(['message' => 'Draft discarded.']);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * Deliberately loose. See the class docblock — this stores work in
     * progress, so the only real constraints are size ones that stop a
     * runaway autosave writing megabytes on every keystroke.
     */
    private function validateDraft(Request $request): array
    {
        $validated = $request->validate([
            'name'      => ['nullable', 'string', 'max:150'],
            'subject'   => ['nullable', 'string', 'max:255'],
            'blocks'    => ['nullable', 'array', 'max:200'],
            'theme'     => ['nullable', 'array'],
            'body_html' => ['nullable', 'string', 'max:524288'],
            'filters'   => ['nullable', 'array'],
        ], [
            'blocks.max'     => 'That is more blocks than a campaign can hold.',
            'body_html.max'  => 'This campaign is too large to autosave. Try trimming it.',
        ]);

        // Absent keys mean "empty", not "leave alone": this is a full replace,
        // so an editor that removed every block must be able to say so.
        foreach (['name', 'subject', 'blocks', 'theme', 'body_html', 'filters'] as $field) {
            $validated[$field] = $validated[$field] ?? null;
        }

        return $validated;
    }

    private function findOwned(Request $request, int $id): ?CampaignDraft
    {
        return CampaignDraft::where('admin_user_id', $request->user()->id)
            ->where('id', $id)
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Draft not found.',
            'code'    => 'not_found',
        ], 404);
    }

    private function formatSummary(CampaignDraft $draft): array
    {
        return [
            'id'          => $draft->id,
            'label'       => $draft->label,
            'subject'     => $draft->subject,
            'block_count' => is_array($draft->blocks) ? count($draft->blocks) : 0,
            'is_empty'    => $draft->isEmpty(),
            'updated_at'  => $draft->updated_at?->toIso8601String(),
        ];
    }

    private function format(CampaignDraft $draft): array
    {
        return $this->formatSummary($draft) + [
            'blocks'    => $draft->blocks,
            'theme'     => $draft->theme,
            'body_html' => $draft->body_html,
            'filters'   => $draft->filters,
        ];
    }
}
