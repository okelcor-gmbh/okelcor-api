<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BulkCampaignEmail;
use App\Models\BulkEmailCampaign;
use App\Models\CampaignDraft;
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
            // `nullable` matters: clients serialize an unused editor as
            // `blocks: null` alongside a pasted body, and a bare `array` rule
            // turns that into a spurious 422 on a perfectly sendable email.
            'blocks'    => ['nullable', 'required_without:body_html', 'array'],
            'theme'     => ['nullable', 'array'],
            'body_html' => ['nullable', 'required_without:blocks', 'string'],
            'subject'   => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['blocks'])) {
            if ($errors = $renderer->validateBlocks($data['blocks'])) {
                return $this->blockErrors($errors);
            }

            $html = $renderer->render($data['blocks'], $data['theme'] ?? []);
            $text = $renderer->renderText($data['blocks']);
        } else {
            $html = $this->sanitizePastedBody($sanitizer, $data['body_html'] ?? null);
            if ($html instanceof JsonResponse) {
                return $html;
            }
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
            'blocks'    => ['nullable', 'required_without:body_html', 'array'],
            'theme'     => ['nullable', 'array'],
            'body_html' => ['nullable', 'required_without:blocks', 'string'],
        ]);

        if (! empty($data['blocks'])) {
            if ($errors = $renderer->validateBlocks($data['blocks'])) {
                return $this->blockErrors($errors);
            }

            $html = $renderer->render($data['blocks'], $data['theme'] ?? []);
            $text = $renderer->renderText($data['blocks']);
        } else {
            $html = $this->sanitizePastedBody($sanitizer, $data['body_html'] ?? null);
            if ($html instanceof JsonResponse) {
                return $html;
            }
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
            'blocks'          => ['nullable', 'required_without:body_html', 'array'],
            'theme'           => ['nullable', 'array'],
            'body_html'       => ['nullable', 'required_without:blocks', 'string'],
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

            // Optional. When the campaign was composed from an autosaved
            // draft, sending it retires that draft — see the end of this
            // method. Omitting it changes nothing.
            'draft_id'        => ['nullable', 'integer'],
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
            $bodyHtml = $this->sanitizePastedBody($sanitizer, $request->input('body_html'));
            if ($bodyHtml instanceof JsonResponse) {
                return $bodyHtml;
            }
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

        // Retire the draft only once the campaign is safely queued. Deleting
        // it earlier would destroy the marketer's work if any step above
        // failed — the draft is the only copy until this point.
        //
        // Scoped to the caller so one admin cannot delete another's draft by
        // guessing an id, and silent when the id is unknown: the campaign did
        // send, and failing the request over draft bookkeeping would tell the
        // marketer their send failed when it did not.
        if ($request->filled('draft_id')) {
            try {
                CampaignDraft::where('admin_user_id', $request->user()->id)
                    ->where('id', $request->integer('draft_id'))
                    ->delete();
            } catch (\Throwable $e) {
                // The campaign is already queued; a draft-bookkeeping failure
                // must not be reported to the marketer as a failed send.
                Log::warning('BulkEmail: draft cleanup failed after queueing campaign', [
                    'campaign_id' => $campaign->id,
                    'draft_id'    => $request->integer('draft_id'),
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data'    => $this->formatCampaign($campaign->fresh()),
            'message' => "Campaign queued for {$campaign->total_recipients} contacts.",
        ], 201);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/bulk-emails/scoreboard — marketing.manage
    //
    // The boss's feedback tracker: every tracked campaign's open rate and
    // completion rate (a recipient who clicked through completed), rolled up
    // into a 0–100 score per campaign and per marketer. Campaigns sent
    // before tracking existed report as untracked, never as zero engagement.
    //
    // Score = 60% open rate + 40% completion rate. Opens are directional —
    // image-blocking undercounts them and Apple's mail privacy inflates
    // them — which is why completion (a real click) carries meaningful
    // weight despite being the rarer event.
    // -------------------------------------------------------------------------
    public function scoreboard(): JsonResponse
    {
        $stats = \DB::table('bulk_email_campaign_recipients')
            ->groupBy('campaign_id')
            ->select(
                'campaign_id',
                \DB::raw('SUM(CASE WHEN tracking_token IS NOT NULL THEN 1 ELSE 0 END) as tracked'),
                \DB::raw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as delivered"),
                \DB::raw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened'),
                \DB::raw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked'),
            )
            ->get()->keyBy('campaign_id');

        $campaigns = BulkEmailCampaign::with('creator:id,name,display_name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (BulkEmailCampaign $c) use ($stats) {
                $s         = $stats->get($c->id);
                $tracked   = (int) ($s->tracked ?? 0) > 0;
                $delivered = (int) ($s->delivered ?? $c->sent_count);
                $opened    = (int) ($s->opened ?? 0);
                $clicked   = (int) ($s->clicked ?? 0);

                $openRate       = $tracked && $delivered > 0 ? round($opened / $delivered * 100, 1) : null;
                $completionRate = $tracked && $delivered > 0 ? round($clicked / $delivered * 100, 1) : null;

                return [
                    'id'              => $c->id,
                    'subject'         => $c->subject,
                    'created_by'      => $c->creator ? trim($c->creator->display_name ?: $c->creator->name) : null,
                    'created_by_id'   => $c->created_by,
                    'created_at'      => $c->created_at?->toIso8601String(),
                    'status'          => $c->status,
                    'delivered'       => $delivered,
                    'opened'          => $opened,
                    'clicked'         => $clicked,
                    'open_rate'       => $openRate,
                    'completion_rate' => $completionRate,
                    'tracked'         => $tracked,
                    'score'           => ($openRate !== null && $completionRate !== null)
                        ? (int) round(0.6 * $openRate + 0.4 * $completionRate)
                        : null,
                ];
            })->values();

        // Per marketer, over their TRACKED campaigns only.
        $marketers = $campaigns
            ->filter(fn ($c) => $c['tracked'] && $c['created_by_id'] !== null)
            ->groupBy('created_by_id')
            ->map(function ($group) {
                $delivered = $group->sum('delivered');
                $opened    = $group->sum('opened');
                $clicked   = $group->sum('clicked');
                $openRate       = $delivered > 0 ? round($opened / $delivered * 100, 1) : 0.0;
                $completionRate = $delivered > 0 ? round($clicked / $delivered * 100, 1) : 0.0;

                return [
                    'name'            => $group->first()['created_by'] ?? 'Unknown',
                    'campaigns'       => $group->count(),
                    'delivered'       => $delivered,
                    'opened'          => $opened,
                    'clicked'         => $clicked,
                    'open_rate'       => $openRate,
                    'completion_rate' => $completionRate,
                    'score'           => (int) round(0.6 * $openRate + 0.4 * $completionRate),
                ];
            })->sortByDesc('score')->values();

        return response()->json([
            'data' => [
                'campaigns' => $campaigns,
                'marketers' => $marketers,
            ],
            'meta' => [
                'score_formula' => 'score = 60% open rate + 40% completion rate (completion = recipient clicked a link)',
                'caveats'       => 'Opens are directional: image-blocking undercounts, Apple Mail privacy inflates. Clicks are hard evidence.',
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Block problems come back as a plain list under `errors.blocks`, already
     * phrased for a non-technical user ("Block 3 (Button): …"), so the editor
     * can show each one next to the block it belongs to.
     *
     * @param  array<int, string>  $errors
     */
    /**
     * Sanitize a pasted HTML body, or explain why it can't be used.
     *
     * Returns the cleaned HTML, or a 422 JsonResponse ready to send back:
     * an empty/missing body (e.g. `blocks: []` alongside no `body_html`
     * slips past `required_without`), or HTML the purifier cannot process
     * — which without this guard surfaced as a bare 500 the panel could
     * only render as "something went wrong".
     */
    private function sanitizePastedBody(ArticleHtmlSanitizer $sanitizer, ?string $bodyHtml): string|JsonResponse
    {
        if ($bodyHtml === null || trim($bodyHtml) === '') {
            return response()->json([
                'message' => 'The email has no content yet — add blocks or paste the HTML body.',
                'code'    => 'empty_body',
            ], 422);
        }

        try {
            return $sanitizer->sanitize($bodyHtml);
        } catch (\RuntimeException) {
            return response()->json([
                'message' => 'That HTML could not be processed. Simplify the pasted markup and try again.',
                'code'    => 'body_unprocessable',
            ], 422);
        }
    }

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
