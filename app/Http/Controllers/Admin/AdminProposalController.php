<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ProposalEmail;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use App\Services\TradeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminProposalController extends Controller
{
    /**
     * POST /admin/quote-requests/{id}/proposal/draft
     *
     * Create or update a proposal draft.
     *
     * If `items` are provided in the request, they are used as-is.
     * If omitted, items are auto-built from the quote's existing tyre_items or
     * legacy tyre_size/quantity fields — unit_price defaults to 0.00 (admin fills
     * in pricing before marking ready).
     *
     * Returns 422 code:proposal_items_missing only when the quote has no item
     * data at all and no items were provided.
     */
    public function draft(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $this->guardNotConverted($quote);

        $data = $request->validate([
            'items'              => ['sometimes', 'nullable', 'array'],
            'items.*.name'       => ['required_with:items', 'string', 'max:300'],
            'items.*.brand'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.sku'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.size'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'items.*.quantity'   => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'currency'           => ['sometimes', 'string', 'size:3'],
            'expires_days'       => ['sometimes', 'integer', 'min:1', 'max:365'],
            'notes'              => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);

        // Resolve line items — use provided items or fall back to persisted quote items.
        // `items` relationship => quote_request_items table (the same table the
        // AdminQuoteRequestItemController editor writes to).
        $requestItemsCount   = is_array($data['items'] ?? null) ? count($data['items']) : 0;
        $persistedItemsCount = $quote->items()->count();

        $rawItems = ! empty($data['items']) ? $data['items'] : null;

        if ($rawItems === null) {
            $rawItems = $this->buildItemsFromQuote($quote);
        }

        if (empty($rawItems)) {
            // TEMP DIAGNOSTIC (CRM-7 Fix 3): reveals request-vs-persisted mismatch.
            // Remove once the proposal/items source-of-truth issue is confirmed resolved.
            Log::warning('[proposal_items_missing] No items resolved for proposal draft', [
                'event'                  => 'proposal_items_missing',
                'quote_request_id'       => $quote->id,
                'quote_ref'              => $quote->ref_number,
                'request_items_count'    => $requestItemsCount,
                'persisted_items_count'  => $persistedItemsCount,
                'relationship_name_used' => 'items', // QuoteRequest::items() => quote_request_items
                'tyre_items_count'       => is_array($quote->tyre_items) ? count($quote->tyre_items) : 0,
                'has_legacy_tyre_size'   => filled($quote->tyre_size),
                'by_admin'               => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'This quote request has no items to create a proposal from. Add quote items first.',
                'code'    => 'proposal_items_missing',
            ], 422);
        }

        // Build line items with computed line_total
        $lineItems = array_map(function (array $item) {
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $lineTotal = round($unitPrice * (int) $item['quantity'], 2);
            return [
                'name'       => $item['name'],
                'brand'      => $item['brand'] ?? null,
                'sku'        => $item['sku'] ?? null,
                'size'       => $item['size'] ?? null,
                'quantity'   => (int) $item['quantity'],
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }, $rawItems);

        $total = round(array_sum(array_column($lineItems, 'line_total')), 2);

        // Generate proposal number only on first draft
        if (! $quote->proposal_number) {
            $quote->proposal_number = app(TradeDocumentService::class)->generateProposalNumber();
        }

        $expiresDays = $data['expires_days'] ?? 30;

        $quote->update([
            'proposal_status'   => 'draft',
            'proposal_number'   => $quote->proposal_number,
            'proposal_items'    => $lineItems,
            'proposal_total'    => $total,
            'proposal_currency' => strtoupper($data['currency'] ?? 'EUR'),
            'proposal_expires_at' => now()->addDays($expiresDays),
            // Clear any previous acceptance state if re-drafting
            'proposal_acceptance_token' => null,
            'proposal_sent_at'          => null,
            'proposal_accepted_at'      => null,
            'proposal_rejected_at'      => null,
            'proposal_accepted_ip'      => null,
            'proposal_accepted_user_agent' => null,
            'proposal_acceptance_note'  => null,
            'proposal_rejection_reason' => null,
        ]);

        if ($request->filled('notes')) {
            $quote->update(['internal_notes' => $data['notes']]);
        }

        Log::info('[proposal_drafted] Proposal drafted', [
            'event'           => 'proposal_drafted',
            'quote_ref'       => $quote->ref_number,
            'proposal_number' => $quote->proposal_number,
            'total'           => $total,
            'by_admin'        => $request->user()?->id,
        ]);

        $this->logCommunication($quote, $request, 'system', 'internal',
            'Proposal drafted',
            "Proposal {$quote->proposal_number} drafted. Total: {$total} {$quote->proposal_currency}.");

        return response()->json([
            'data'    => $this->formatProposal($quote->fresh()),
            'message' => "Proposal {$quote->proposal_number} drafted.",
        ], 201);
    }

    /**
     * POST /admin/quote-requests/{id}/proposal/mark-ready
     *
     * Mark a draft proposal as ready for sending.
     */
    public function markReady(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $this->guardNotConverted($quote);

        if (! in_array($quote->proposal_status, ['draft', 'ready'], true)) {
            return response()->json([
                'message' => 'Only a draft proposal can be marked as ready.',
                'code'    => 'proposal_invalid_state',
                'proposal_status' => $quote->proposal_status,
            ], 422);
        }

        if (! $quote->proposal_items || count($quote->proposal_items) === 0) {
            return response()->json([
                'message' => 'Proposal must have at least one line item before it can be marked ready.',
                'code'    => 'proposal_no_items',
            ], 422);
        }

        $quote->update(['proposal_status' => 'ready']);

        // Generate PDF
        $pdfPath = app(TradeDocumentService::class)->generateProposalPdf($quote->fresh());
        if ($pdfPath) {
            $quote->update(['proposal_pdf_path' => $pdfPath]);
        }

        Log::info('[proposal_ready] Proposal marked ready', [
            'event'           => 'proposal_ready',
            'quote_ref'       => $quote->ref_number,
            'proposal_number' => $quote->proposal_number,
            'by_admin'        => $request->user()?->id,
        ]);

        $this->logCommunication($quote, $request, 'system', 'internal',
            'Proposal ready',
            "Proposal {$quote->proposal_number} marked ready for sending.");

        return response()->json([
            'data'    => $this->formatProposal($quote->fresh()),
            'message' => 'Proposal marked as ready.',
        ]);
    }

    /**
     * POST /admin/quote-requests/{id}/proposal/send
     *
     * Send proposal email with acceptance link to the customer.
     * Sets proposal_status = 'sent', generates token (30-day TTL).
     * Updates qualification_status = 'proposal_sent'.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $this->guardNotConverted($quote);

        if (! in_array($quote->proposal_status, ['draft', 'ready', 'sent'], true)) {
            return response()->json([
                'message' => 'Proposal must be in draft or ready state before it can be sent.',
                'code'    => 'proposal_invalid_state',
                'proposal_status' => $quote->proposal_status,
            ], 422);
        }

        if (! $quote->proposal_items || count($quote->proposal_items) === 0) {
            return response()->json([
                'message' => 'Proposal has no line items. Please draft the proposal first.',
                'code'    => 'proposal_no_items',
            ], 422);
        }

        $data = $request->validate([
            'recipient_email' => ['sometimes', 'nullable', 'email'],
            'message'         => ['sometimes', 'nullable', 'string', 'max:3000'],
            'expires_days'    => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $recipientEmail = $data['recipient_email'] ?? $quote->email;

        if (! $recipientEmail) {
            return response()->json([
                'message' => 'No recipient email address available for this quote.',
                'code'    => 'missing_recipient_email',
            ], 422);
        }

        // Generate acceptance token (64-char hex, 30-day TTL)
        $token      = bin2hex(random_bytes(32));
        $expiresDays = $data['expires_days'] ?? 30;

        // Ensure proposal has a number and PDF
        if (! $quote->proposal_number) {
            $quote->proposal_number = app(TradeDocumentService::class)->generateProposalNumber();
        }

        $quote->update([
            'proposal_status'           => 'sent',
            'proposal_number'           => $quote->proposal_number,
            'proposal_acceptance_token' => $token,
            'proposal_sent_at'          => now(),
            'proposal_expires_at'       => now()->addDays($expiresDays),
            'qualification_status'      => 'proposal_sent',
        ]);

        // Regenerate PDF with latest data
        $pdfPath = app(TradeDocumentService::class)->generateProposalPdf($quote->fresh());
        if ($pdfPath) {
            $quote->update(['proposal_pdf_path' => $pdfPath]);
        }

        $acceptUrl = rtrim(config('app.frontend_url', config('app.url')), '/') . '/proposals/accept/' . $token;

        // Send email — non-blocking
        $emailSent = false;
        try {
            Mail::to($recipientEmail)->send(new ProposalEmail(
                $quote->fresh(),
                $acceptUrl,
                $data['message'] ?? null,
                $request->user()
            ));
            $emailSent = true;

            Log::info('[proposal_sent] Proposal email sent', [
                'event'           => 'proposal_sent',
                'quote_ref'       => $quote->ref_number,
                'proposal_number' => $quote->proposal_number,
                'to'              => $recipientEmail,
                'by_admin'        => $request->user()?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[proposal_send_failed] Proposal email send failed', [
                'quote_ref' => $quote->ref_number,
                'to'        => $recipientEmail,
                'error'     => $e->getMessage(),
            ]);
        }

        $this->logCommunication(
            $quote, $request, 'email', 'outbound',
            "Proposal {$quote->proposal_number} sent",
            $data['message'] ?? "Proposal sent to {$recipientEmail}.",
            $emailSent ? 'sent' : 'failed'
        );

        return response()->json([
            'data' => array_merge($this->formatProposal($quote->fresh()), [
                'accept_url'    => $acceptUrl,
                'recipient'     => $recipientEmail,
                'email_sent'    => $emailSent,
            ]),
            'message' => $emailSent
                ? "Proposal sent to {$recipientEmail}."
                : "Proposal status updated but email delivery failed. Check mail config.",
        ]);
    }

    /**
     * POST /admin/quote-requests/{id}/proposal/generate-link
     *
     * Generate or rotate the proposal acceptance link without sending an email.
     * Useful for sharing the link manually (e.g. via WhatsApp).
     */
    public function generateLink(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $this->guardNotConverted($quote);

        if (! in_array($quote->proposal_status, ['draft', 'ready', 'sent'], true)) {
            return response()->json([
                'message' => 'Proposal must be drafted before a link can be generated.',
                'code'    => 'proposal_invalid_state',
            ], 422);
        }

        $data = $request->validate([
            'expires_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        if (! $quote->proposal_number) {
            $quote->proposal_number = app(TradeDocumentService::class)->generateProposalNumber();
        }

        $token       = bin2hex(random_bytes(32));
        $expiresDays = $data['expires_days'] ?? 30;

        $quote->update([
            'proposal_number'           => $quote->proposal_number,
            'proposal_acceptance_token' => $token,
            'proposal_expires_at'       => now()->addDays($expiresDays),
        ]);

        $acceptUrl = rtrim(config('app.frontend_url', config('app.url')), '/') . '/proposals/accept/' . $token;

        return response()->json([
            'data' => [
                'accept_url'  => $acceptUrl,
                'expires_at'  => $quote->fresh()->proposal_expires_at?->toIso8601String(),
                'reject_url'  => rtrim(config('app.frontend_url', config('app.url')), '/') . '/proposals/reject/' . $token,
            ],
            'message' => 'Acceptance link generated.',
        ]);
    }

    /**
     * POST /admin/quote-requests/{id}/proposal/void
     *
     * Void the current proposal with a reason.
     * Clears acceptance token. Resets proposal_status to 'none'.
     */
    public function void(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $this->guardNotConverted($quote);

        if ($quote->proposal_status === 'none') {
            return response()->json([
                'message' => 'No proposal exists to void.',
                'code'    => 'no_proposal',
            ], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $quote->update([
            'proposal_status'           => 'none',
            'proposal_acceptance_token' => null,
            'proposal_voided_at'        => now(),
            'proposal_voided_by'        => $request->user()?->id,
            'proposal_void_reason'      => $data['reason'],
        ]);

        Log::info('[proposal_voided] Proposal voided', [
            'event'           => 'proposal_voided',
            'quote_ref'       => $quote->ref_number,
            'proposal_number' => $quote->proposal_number,
            'reason'          => $data['reason'],
            'by_admin'        => $request->user()?->id,
        ]);

        $this->logCommunication($quote, $request, 'system', 'internal',
            'Proposal voided',
            "Proposal {$quote->proposal_number} voided. Reason: {$data['reason']}");

        return response()->json([
            'data'    => $this->formatProposal($quote->fresh()),
            'message' => 'Proposal voided.',
        ]);
    }

    /**
     * GET /admin/quote-requests/{id}/proposal/download
     *
     * Download the proposal PDF from private disk.
     */
    public function download(Request $request, int $id)
    {
        $quote = QuoteRequest::findOrFail($id);

        $pdfPath = $quote->getRawOriginal('proposal_pdf_path');

        if (! $pdfPath) {
            return response()->json([
                'message' => 'No proposal PDF has been generated yet.',
                'code'    => 'no_proposal_pdf',
            ], 404);
        }

        if (! Storage::disk('local')->exists($pdfPath)) {
            return response()->json([
                'message' => 'Proposal PDF file was not found on disk.',
                'code'    => 'proposal_pdf_missing',
            ], 404);
        }

        $filename = ($quote->proposal_number ?? 'proposal') . '.pdf';

        return response()->streamDownload(function () use ($pdfPath) {
            echo Storage::disk('local')->get($pdfPath);
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Auto-build proposal line items from the quote's stored data.
     *
     * Priority (highest to lowest):
     *   1. quote_request_items table rows (admin-curated, may have unit_price)
     *   2. quote.tyre_items JSON array (multi-row, no price)
     *   3. Legacy quote.tyre_size + quote.quantity (single-row, no price)
     *
     * unit_price defaults to 0.00 when not set — proposal total will be 0
     * and admin should update prices before mark-ready.
     *
     * @return array<int, array>
     */
    private function buildItemsFromQuote(QuoteRequest $quote): array
    {
        // 1. Admin-curated items from quote_request_items table (preferred)
        $quote->loadMissing('items');

        if ($quote->items->isNotEmpty()) {
            return $quote->items->map(function ($item) {
                $label = trim(implode(' ', array_filter([
                    $item->brand,
                    $item->model,
                    $item->size,
                ])));

                return [
                    'name'       => $label ?: ($item->size ?? $item->brand ?? 'Tyre'),
                    'brand'      => $item->brand,
                    'sku'        => null,
                    'size'       => $item->size,
                    'quantity'   => $item->quantity,
                    'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : 0.00,
                ];
            })->all();
        }

        $brand = $quote->brand_preference ?: null;

        // 2. tyre_items JSON (multi-row, no price)
        if (! empty($quote->tyre_items) && is_array($quote->tyre_items)) {
            $items = [];
            foreach ($quote->tyre_items as $row) {
                $size = trim((string) ($row['size'] ?? ''));
                if ($size === '') {
                    continue;
                }
                $qty    = max(1, (int) ($row['quantity'] ?? 1));
                $items[] = [
                    'name'       => $brand ? "{$brand} {$size}" : $size,
                    'brand'      => $brand,
                    'sku'        => null,
                    'size'       => $size,
                    'quantity'   => $qty,
                    'unit_price' => 0.00,
                ];
            }
            if (! empty($items)) {
                return $items;
            }
        }

        // 3. Legacy single-row tyre_size + quantity
        $legacySize = trim((string) ($quote->tyre_size ?? ''));
        if ($legacySize !== '') {
            preg_match('/^(\d+)/', (string) ($quote->quantity ?? '1'), $qtyMatch);
            $qty = max(1, (int) ($qtyMatch[1] ?? 1));

            return [[
                'name'       => $brand ? "{$brand} {$legacySize}" : $legacySize,
                'brand'      => $brand,
                'sku'        => null,
                'size'       => $legacySize,
                'quantity'   => $qty,
                'unit_price' => 0.00,
            ]];
        }

        return [];
    }

    private function guardNotConverted(QuoteRequest $quote): void
    {
        if ($quote->proposal_status === 'converted') {
            abort(409, 'This proposal has already been converted to an order and cannot be modified.');
        }
    }

    private function logCommunication(
        QuoteRequest $quote,
        Request $request,
        string $type,
        string $direction,
        string $subject,
        string $body,
        string $status = 'completed'
    ): void {
        try {
            CustomerCommunication::create([
                'quote_request_id' => $quote->id,
                'customer_id'      => $quote->customer_id,
                'admin_user_id'    => $request->user()?->id,
                'type'             => $type,
                'direction'        => $direction,
                'subject'          => $subject,
                'body'             => $body,
                'status'           => $status,
                'completed_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CustomerCommunication write failed (proposal)', [
                'quote_ref' => $quote->ref_number,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    public function formatProposal(QuoteRequest $r): array
    {
        return [
            'proposal_status'          => $r->proposal_status ?? 'none',
            'proposal_number'          => $r->proposal_number,
            'proposal_items'           => $r->proposal_items ?? [],
            'proposal_total'           => $r->proposal_total ? (float) $r->proposal_total : null,
            'proposal_currency'        => $r->proposal_currency ?? 'EUR',
            'proposal_sent_at'         => $r->proposal_sent_at?->toIso8601String(),
            'proposal_accepted_at'     => $r->proposal_accepted_at?->toIso8601String(),
            'proposal_rejected_at'     => $r->proposal_rejected_at?->toIso8601String(),
            'proposal_expires_at'      => $r->proposal_expires_at?->toIso8601String(),
            'proposal_voided_at'       => $r->proposal_voided_at?->toIso8601String(),
            'proposal_voided_by'       => $r->proposal_voided_by,
            'proposal_void_reason'     => $r->proposal_void_reason,
            'proposal_rejection_reason' => $r->proposal_rejection_reason,
            'proposal_acceptance_note' => $r->proposal_acceptance_note,
            'has_proposal_pdf'         => (bool) $r->getRawOriginal('proposal_pdf_path'),
            'proposal_expired'         => $r->proposal_expires_at && $r->proposal_expires_at->isPast()
                                          && $r->proposal_status === 'sent',
        ];
    }
}
