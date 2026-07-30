<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BulkCampaignEmail;
use App\Models\BulkEmailCampaign;
use App\Services\ArticleHtmlSanitizer;
use App\Services\BulkEmailService;
use App\Services\CampaignBlockRenderer;
use App\Services\CampaignMergeTags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminBulkEmailController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/bulk-emails — marketing.manage
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = BulkEmailCampaign::with('creator:id,name')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn ($c) => $this->formatCampaign($c))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/bulk-emails/recipient-count — marketing.manage
    // Lets the UI show "this will send to N contacts" before committing.
    // -------------------------------------------------------------------------
    public function recipientCount(Request $request, BulkEmailService $service): JsonResponse
    {
        $filters = $request->only(['market', 'markets', 'company', 'country', 'status', 'search']);

        return response()->json(['data' => ['count' => $service->countRecipients($filters)]]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/bulk-emails/{id} — marketing.manage
    // -------------------------------------------------------------------------
    public function show(int $id): JsonResponse
    {
        $campaign = BulkEmailCampaign::with('creator:id,name')->findOrFail($id);

        return response()->json(['data' => $this->formatCampaign($campaign, detailed: true)]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/bulk-emails/preview — marketing.manage
    //
    // Renders blocks (or pasted HTML) without creating anything, so the editor
    // can show a live preview. Returns the real HTML that would be sent, a
    // sample-personalized copy with merge tags filled in, and the plain-text
    // alternative — plus any misspelled merge tags, caught here rather than
    // after 1,700 emails went out with a blank in them.
    // -------------------------------------------------------------------------
    public function preview(
        Request $request,
        CampaignBlockRenderer $renderer,
        CampaignMergeTags $mergeTags,
        ArticleHtmlSanitizer $sanitizer
    ): JsonResponse {
        $data = $request->validate([
            'blocks'    => ['required_without:body_html', 'array'],
            'theme'     => ['nullable', 'array'],
            'body_html' => ['required_without:blocks', 'string'],
            'subject'   => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['blocks'])) {
            if ($errors = $renderer->validateBlocks($data['blocks'])) {
                return $this->blockErrors($errors);
            }

            $html = $renderer->render($data['blocks'], $data['theme'] ?? []);
            $text = $renderer->renderText($data['blocks']);
        } else {
            $html = $sanitizer->sanitize($data['body_html']);
            $text = null;
        }

        $subject = (string) ($data['subject'] ?? '');

        return response()->json([
            'data' => [
                'html'              => $html,
                'html_personalized' => $mergeTags->applySamples($html),
                'text'              => $text,
                'subject'           => $subject,
                'subject_personalized' => $mergeTags->applySamples($subject),
                'unknown_merge_tags'   => array_values(array_unique(array_merge(
                    $mergeTags->unknownTags($html),
                    $mergeTags->unknownTags($subject)
                ))),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/bulk-emails/test-send — marketing.manage
    //
    // Sends one real email to an address the marketer chooses (normally their
    // own) so they can check it in a real inbox before committing to the list.
    // Creates no campaign, touches no contact, records no recipient — and is
    // therefore the one safe way to answer "will this actually look right?".
    // -------------------------------------------------------------------------
    public function testSend(
        Request $request,
        CampaignBlockRenderer $renderer,
        CampaignMergeTags $mergeTags,
        ArticleHtmlSanitizer $sanitizer
    ): JsonResponse {
        $data = $request->validate([
            'to'        => ['required', 'email', 'max:255'],
            'subject'   => ['required', 'string', 'max:255'],
            'blocks'    => ['required_without:body_html', 'array'],
            'theme'     => ['nullable', 'array'],
            'body_html' => ['required_without:blocks', 'string'],
        ]);

        if (! empty($data['blocks'])) {
            if ($errors = $renderer->validateBlocks($data['blocks'])) {
                return $this->blockErrors($errors);
            }

            $html = $renderer->render($data['blocks'], $data['theme'] ?? []);
            $text = $renderer->renderText($data['blocks']);
        } else {
            $html = $sanitizer->sanitize($data['body_html']);
            $text = null;
        }

        // The unsubscribe link is neutralised FIRST, before sample values are
        // filled in — otherwise the generic sample for [[UNSUBSCRIBE_URL]] would
        // stand in for it, which looks like a working unsubscribe link and isn't.
        // Nothing here may resolve to a live token: a tester clicking through
        // their own test must not unsubscribe a real contact.
        $inertUnsubscribe = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/#test-send-unsubscribe-disabled';
        $html = str_replace('[[UNSUBSCRIBE_URL]]', $inertUnsubscribe, $html);
        $text = $text === null ? null : str_replace('[[UNSUBSCRIBE_URL]]', $inertUnsubscribe, $text);

        // Sample values, not a real contact's — a test send must never depend on
        // or expose one.
        $html = $mergeTags->applySamples($html);
        $text = $text === null ? null : $mergeTags->applySamples($text);

        try {
            Mail::to($data['to'])->send(new BulkCampaignEmail(
                '[TEST] ' . $data['subject'],
                $html,
                $inertUnsubscribe,
                $text
            ));
        } catch (\Throwable $e) {
            Log::warning('BulkEmail test send failed', ['to' => $data['to'], 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Could not send the test email: ' . $e->getMessage(),
                'code'    => 'test_send_failed',
            ], 502);
        }

        return response()->json(['message' => "Test email sent to {$data['to']}."]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/bulk-emails — marketing.manage
    // Creates the campaign, snapshots the recipient list, and queues sending.
    //
    // Accepts EITHER `blocks` (+ optional `theme`) from the design editor, or
    // `body_html` for a hand-written/pasted document. Blocks are rendered here
    // and stored in `body_html`, so the send path is identical either way.
    // -------------------------------------------------------------------------
    public function store(
        Request $request,
        BulkEmailService $service,
        ArticleHtmlSanitizer $sanitizer,
        CampaignBlockRenderer $renderer
    ): JsonResponse {
        $request->validate([
            'subject'         => ['required', 'string', 'max:255'],
            'blocks'          => ['required_without:body_html', 'array'],
            'theme'           => ['nullable', 'array'],
            'body_html'       => ['required_without:blocks', 'string'],
            'filters'           => ['nullable', 'array'],
            'filters.market'    => ['nullable', 'string', 'max:50'],
            // Several markets in one send. A contact in two of them is still
            // selected once (see BulkEmailService::recipientQuery).
            'filters.markets'   => ['nullable', 'array', 'max:20'],
            'filters.markets.*' => ['string', 'max:50'],
            'filters.company' => ['nullable', 'string', 'max:150'],
            'filters.country' => ['nullable', 'string', 'max:100'],
            'filters.status'  => ['nullable', 'in:subscribed,unknown'],
            'filters.search'  => ['nullable', 'string', 'max:150'],
        ]);

        $filters = $request->input('filters', []);
        $blocks  = $request->input('blocks');
        $theme   = $request->input('theme');

        if (is_array($blocks) && $blocks !== []) {
            if ($errors = $renderer->validateBlocks($blocks)) {
                return $this->blockErrors($errors);
            }

            // Rendered here, not at send time: one render for the whole
            // campaign, and the stored HTML is exactly what every recipient
            // gets (bar their own merge-tag values), so a sent campaign can
            // always be inspected after the fact.
            $bodyHtml = $renderer->render($blocks, is_array($theme) ? $theme : []);
            $bodyText = $renderer->renderText($blocks);
        } else {
            $blocks   = null;
            $theme    = null;
            $bodyHtml = $sanitizer->sanitize($request->input('body_html'));
            $bodyText = null;
        }

        if ($service->countRecipients($filters) === 0) {
            return response()->json(['message' => 'No contacts match these filters.'], 422);
        }

        $campaign = $service->createCampaign(
            subject: $request->input('subject'),
            bodyHtml: $bodyHtml,
            filters: $filters,
            createdBy: $request->user()->id,
            blocks: $blocks,
            theme: $theme,
            bodyText: $bodyText,
        );

        $service->dispatch($campaign);

        return response()->json([
            'data'    => $this->formatCampaign($campaign->fresh()),
            'message' => "Campaign queued for {$campaign->total_recipients} contacts.",
        ], 201);
    }

    // -------------------------------------------------------------------------

    /**
     * Block problems come back as a plain list under `errors.blocks`, already
     * phrased for a non-technical user ("Block 3 (Button): …"), so the editor
     * can show each one next to the block it belongs to.
     *
     * @param  array<int, string>  $errors
     */
    private function blockErrors(array $errors): JsonResponse
    {
        return response()->json([
            'message' => 'Some blocks need fixing before this can be sent.',
            'errors'  => ['blocks' => $errors],
            'code'    => 'invalid_blocks',
        ], 422);
    }

    private function formatCampaign(BulkEmailCampaign $c, bool $detailed = false): array
    {
        $data = [
            'id'               => $c->id,
            'subject'          => $c->subject,
            'filters'          => $c->filters,
            // Lets the UI show "designed in the editor" vs "pasted HTML", and
            // offer Duplicate/Reopen only for the former.
            'designed'         => is_array($c->blocks) && $c->blocks !== [],
            'total_recipients' => $c->total_recipients,
            'sent_count'       => $c->sent_count,
            'failed_count'     => $c->failed_count,
            'status'           => $c->status,
            'created_by'       => $c->creator?->name,
            'created_at'       => $c->created_at?->toIso8601String(),
            'completed_at'     => $c->completed_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['body_html'] = $c->body_html;
            // Present when the campaign was built in the editor — the frontend
            // reopens or duplicates it straight from these.
            $data['blocks']    = $c->blocks;
            $data['theme']     = $c->theme;
        }

        return $data;
    }
}
