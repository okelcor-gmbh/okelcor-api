<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BulkEmailCampaign;
use App\Services\ArticleHtmlSanitizer;
use App\Services\BulkEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $filters = $request->only(['market', 'company', 'country', 'status', 'search']);

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
    // POST /api/v1/admin/bulk-emails — marketing.manage
    // Creates the campaign, snapshots the recipient list, and queues sending.
    // -------------------------------------------------------------------------
    public function store(Request $request, BulkEmailService $service, ArticleHtmlSanitizer $sanitizer): JsonResponse
    {
        $request->validate([
            'subject'         => ['required', 'string', 'max:255'],
            'body_html'       => ['required', 'string'],
            'filters'         => ['nullable', 'array'],
            'filters.market'  => ['nullable', 'string', 'max:50'],
            'filters.company' => ['nullable', 'string', 'max:150'],
            'filters.country' => ['nullable', 'string', 'max:100'],
            'filters.status'  => ['nullable', 'in:subscribed,unknown'],
            'filters.search'  => ['nullable', 'string', 'max:150'],
        ]);

        $filters  = $request->input('filters', []);
        $bodyHtml = $sanitizer->sanitize($request->input('body_html'));

        if ($service->countRecipients($filters) === 0) {
            return response()->json(['message' => 'No contacts match these filters.'], 422);
        }

        $campaign = $service->createCampaign(
            subject: $request->input('subject'),
            bodyHtml: $bodyHtml,
            filters: $filters,
            createdBy: $request->user()->id,
        );

        $service->dispatch($campaign);

        return response()->json([
            'data'    => $this->formatCampaign($campaign->fresh()),
            'message' => "Campaign queued for {$campaign->total_recipients} contacts.",
        ], 201);
    }

    // -------------------------------------------------------------------------

    private function formatCampaign(BulkEmailCampaign $c, bool $detailed = false): array
    {
        $data = [
            'id'               => $c->id,
            'subject'          => $c->subject,
            'filters'          => $c->filters,
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
        }

        return $data;
    }
}
