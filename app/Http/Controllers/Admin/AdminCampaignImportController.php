<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignTemplate;
use App\Services\CampaignBlockRenderer;
use App\Services\InDesignEmailImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Importing a design the marketing team produced in InDesign.
 *
 * They design there because that is where they can make something that looks
 * good, and they were previously handing the exported folder to a developer to
 * turn into a campaign by hand — which meant every new design needed a backend
 * change. This endpoint is that job done by the system instead.
 *
 * The import produces a saved CampaignTemplate: blocks + theme, editable in the
 * campaign editor, reusable for every future send, with the pictures registered
 * in the Media Library so the next campaign can reach them without importing
 * anything at all.
 *
 * See InDesignEmailImporter for what is recovered and — just as importantly —
 * what cannot be.
 */
class AdminCampaignImportController extends Controller
{
    // -------------------------------------------------------------------------
    // POST /api/v1/admin/campaign-templates/import — marketing.manage
    //
    // multipart/form-data:
    //   file        the exported InDesign folder, zipped   (required)
    //   name        template name                          (required unless dry_run)
    //   description                                        (optional)
    //   dry_run     true to convert and return without saving anything
    // -------------------------------------------------------------------------
    public function store(Request $request, InDesignEmailImporter $importer, CampaignBlockRenderer $renderer): JsonResponse
    {
        $dryRun = $request->boolean('dry_run');

        $data = $request->validate([
            // 'zip' relies on a mime lookup that varies by host; the importer
            // opens the archive itself and fails cleanly if it is not one.
            'file' => ['required', 'file', 'max:51200'],
            // A dry run saves nothing, so it needs no name. Anything else does —
            // an untitled template is one nobody can find again. Expressed as a
            // closure because `required_if` does not fire when the other field
            // is absent, which is the commonest way a real save arrives.
            'name'        => [Rule::requiredIf(fn () => ! $dryRun), 'nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'dry_run'     => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $importer->import($request->file('file'), $request->user()->id);
        } catch (RuntimeException $e) {
            // Everything thrown from the importer is already phrased for the
            // marketer who uploaded the file.
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'import_failed',
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[indesign_import] conversion failed', [
                'admin_id' => $request->user()->id,
                'file'     => $request->file('file')?->getClientOriginalName(),
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'That export could not be read. It may not be an InDesign HTML export.',
                'code'    => 'import_failed',
            ], 422);
        }

        // The importer only ever emits block types it builds itself, but this is
        // the same gate a hand-built template passes — a template that cannot
        // render is not worth saving, and finding that out at send time is far
        // more expensive than finding it out here.
        if ($errors = $renderer->validateBlocks($result['blocks'])) {
            Log::warning('[indesign_import] produced blocks that failed validation', ['errors' => $errors]);

            return response()->json([
                'message' => 'The design was read, but it could not be turned into a valid campaign.',
                'errors'  => ['blocks' => $errors],
                'code'    => 'invalid_blocks',
            ], 422);
        }

        $payload = [
            'blocks'   => $result['blocks'],
            'theme'    => $result['theme'],
            'media'    => $result['media'],
            'warnings' => $result['warnings'],
            'source'   => $result['source'],
            // Rendered here so the editor can show the real email immediately,
            // rather than asking for a separate preview round-trip to find out
            // whether the import was any good.
            'preview_html' => $renderer->render($result['blocks'], $result['theme']),
        ];

        if ($dryRun) {
            return response()->json([
                'data'    => $payload + ['saved' => false],
                'message' => 'Design read. Nothing was saved.',
            ]);
        }

        $template = CampaignTemplate::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? 'Imported from an InDesign export.',
            'blocks'      => $result['blocks'],
            'theme'       => $result['theme'],
            'created_by'  => $request->user()->id,
        ]);

        return response()->json([
            'data' => $payload + [
                'saved'       => true,
                'template_id' => $template->id,
                'name'        => $template->name,
            ],
            'message' => 'Design imported and saved as a reusable template.',
        ], 201);
    }
}
