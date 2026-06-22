<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\QuoteRequest;
use App\Models\TradeDocument;
use App\Services\AdminNotificationService;
use App\Services\CustomerTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerQuoteAcceptanceController extends Controller
{
    /**
     * POST /api/v1/auth/quotes/{id}/accept
     *
     * Authenticated customer accepts a quoted proposal.
     * Marks the quote as accepted so admin can proceed to convert it to an order.
     * Does NOT auto-create an order — admin still controls the conversion.
     */
    public function acceptQuote(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $quote = QuoteRequest::findOrFail($id);

        if (! $this->customerOwnsQuote($customer, $quote)) {
            return response()->json(['message' => 'Quote not found.'], 404);
        }

        if ($quote->status !== 'quoted') {
            return response()->json([
                'message' => 'Only quotes that have been formally priced can be accepted.',
            ], 422);
        }

        if ($quote->customer_acceptance_status === 'accepted') {
            return response()->json([
                'message'    => 'Quote has already been accepted.',
                'data'       => ['customer_acceptance_status' => 'accepted'],
            ], 409);
        }

        if ($quote->order_id !== null) {
            return response()->json([
                'message' => 'This quote has already been converted to an order.',
            ], 409);
        }

        $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $quote->update([
            'customer_acceptance_status'  => 'accepted',
            'customer_accepted_at'        => now(),
            'customer_accepted_ip'        => $request->ip(),
            'customer_accepted_user_agent' => $request->userAgent(),
            'customer_acceptance_note'    => $request->input('note'),
        ]);

        $this->logQuoteEvent($quote, 'customer_proposal_accepted',
            'Customer accepted quote ' . $quote->ref_number . '.');

        return response()->json([
            'data'    => ['customer_acceptance_status' => 'accepted'],
            'message' => 'Quote accepted. Okelcor will proceed with your order.',
        ]);
    }

    /**
     * POST /api/v1/auth/quotes/{id}/reject
     *
     * Authenticated customer rejects a quoted proposal.
     */
    public function rejectQuote(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $quote = QuoteRequest::findOrFail($id);

        if (! $this->customerOwnsQuote($customer, $quote)) {
            return response()->json(['message' => 'Quote not found.'], 404);
        }

        if (! in_array($quote->status, ['quoted', 'reviewed'], true)) {
            return response()->json([
                'message' => 'This quote cannot be rejected in its current state.',
            ], 422);
        }

        if ($quote->customer_acceptance_status === 'rejected') {
            return response()->json([
                'message' => 'Quote has already been rejected.',
            ], 409);
        }

        if ($quote->order_id !== null) {
            return response()->json([
                'message' => 'This quote has already been converted to an order and cannot be rejected.',
            ], 409);
        }

        $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $quote->update([
            'customer_acceptance_status'  => 'rejected',
            'customer_accepted_at'        => now(),
            'customer_accepted_ip'        => $request->ip(),
            'customer_accepted_user_agent' => $request->userAgent(),
            'customer_acceptance_note'    => $request->input('note'),
        ]);

        $this->logQuoteEvent($quote, 'customer_proposal_rejected',
            'Customer rejected quote ' . $quote->ref_number
            . ($request->filled('note') ? '. Reason: ' . $request->input('note') : '.'));

        return response()->json([
            'message' => 'Quote rejected. We are sorry to hear that. Please contact Okelcor if you have any questions.',
        ]);
    }

    /**
     * POST /api/v1/auth/orders/{ref}/accept-order-confirmation
     *
     * Authenticated customer accepts the Order Confirmation (AB document).
     * Unlocks proforma generation.
     */
    public function acceptOrderConfirmation(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        $order = Order::where('ref', $ref)->first();

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->customer_acceptance_status === 'accepted') {
            return response()->json([
                'message' => 'Order confirmation has already been accepted.',
                'data'    => ['customer_acceptance_status' => 'accepted'],
            ], 409);
        }

        // Must have an issued/sent order confirmation document
        $hasAb = TradeDocument::where('order_id', $order->id)
            ->where('type', 'order_confirmation')
            ->whereIn('status', ['issued', 'sent'])
            ->exists();

        if (! $hasAb) {
            return response()->json([
                'message' => 'No order confirmation has been issued yet. Please contact Okelcor.',
                'code'    => 'no_order_confirmation',
            ], 422);
        }

        $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $order->update([
            'customer_acceptance_status'  => 'accepted',
            'customer_accepted_at'        => now(),
            'customer_accepted_ip'        => $request->ip(),
            'customer_accepted_user_agent' => $request->userAgent(),
            'customer_acceptance_note'    => $request->input('note'),
            'acceptance_token'            => null,  // invalidate any outstanding token
            'acceptance_token_expires_at' => null,
        ]);

        $this->logOrderEvent($order, 'order_confirmation_accepted',
            'Customer accepted order confirmation for order ' . $order->ref . '.');

        return response()->json([
            'data'    => ['customer_acceptance_status' => 'accepted'],
            'message' => 'Order confirmation accepted. Okelcor will now prepare your proforma invoice.',
        ]);
    }

    /**
     * GET /api/v1/orders/{ref}/accept-confirmation?token={token}
     *
     * Public — returns safe order summary the frontend needs to display before
     * the customer confirms. Does NOT expose PII beyond what the token holder
     * already knows (they clicked the link in their own email).
     */
    public function confirmationTokenInfo(Request $request, string $ref): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);

        $order = Order::where('ref', $ref)
            ->where('acceptance_token', $request->query('token'))
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Invalid or expired acceptance link.',
                'code'    => 'invalid_token',
            ], 403);
        }

        if ($order->acceptance_token_expires_at && $order->acceptance_token_expires_at->isPast()) {
            return response()->json([
                'message' => 'This acceptance link has expired. Please contact Okelcor for a new one.',
                'code'    => 'token_expired',
            ], 403);
        }

        $document = TradeDocument::where('order_id', $order->id)
            ->where('type', 'order_confirmation')
            ->whereIn('status', ['issued', 'sent'])
            ->first();

        return response()->json([
            'data' => [
                'order_ref'                  => $order->ref,
                'order_total'                => (float) $order->total,
                'currency'                   => 'EUR',
                'customer_acceptance_status' => $order->customer_acceptance_status,
                'already_actioned'           => $order->customer_acceptance_status !== 'pending',
                'expires_at'                 => $order->acceptance_token_expires_at?->toIso8601String(),
                'document' => $document ? [
                    'type'   => $document->type,
                    'number' => $document->number,
                ] : null,
            ],
            'message' => 'success',
        ]);
    }

    /**
     * POST /api/v1/orders/{ref}/accept-confirmation
     *
     * Public (no account required) — token-protected.
     * Accepts or rejects the order confirmation via a secure emailed link.
     *
     * Body: { "action": "accept|reject", "token": "...", "note": "..." }
     */
    public function acceptConfirmationByToken(Request $request, string $ref): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'in:accept,reject'],
            'token'  => ['required', 'string', 'size:64'],
            'note'   => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $order = Order::where('ref', $ref)
            ->where('acceptance_token', $request->input('token'))
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Invalid or expired acceptance link.',
                'code'    => 'invalid_token',
            ], 403);
        }

        if ($order->acceptance_token_expires_at && $order->acceptance_token_expires_at->isPast()) {
            return response()->json([
                'message' => 'This acceptance link has expired. Please contact Okelcor for a new one.',
                'code'    => 'token_expired',
            ], 403);
        }

        if ($order->customer_acceptance_status === 'accepted') {
            return response()->json([
                'message' => 'Order confirmation has already been accepted.',
                'data'    => ['customer_acceptance_status' => 'accepted'],
            ], 409);
        }

        $status = $request->input('action') === 'accept' ? 'accepted' : 'rejected';

        $order->update([
            'customer_acceptance_status'  => $status,
            'customer_accepted_at'        => now(),
            'customer_accepted_ip'        => $request->ip(),
            'customer_accepted_user_agent' => $request->userAgent(),
            'customer_acceptance_note'    => $request->input('note'),
            'acceptance_token'            => null,
            'acceptance_token_expires_at' => null,
        ]);

        $action = $status === 'accepted' ? 'order_confirmation_accepted' : 'customer_proposal_rejected';
        $note   = $status === 'accepted'
            ? 'Customer accepted order confirmation via signed link.'
            : 'Customer rejected order confirmation via signed link.'
                . ($request->filled('note') ? ' Reason: ' . $request->input('note') : '');

        $this->logOrderEvent($order, $action, $note);

        $message = $status === 'accepted'
            ? 'Order confirmation accepted. Okelcor will now prepare your proforma invoice.'
            : 'Response recorded. Okelcor will be in touch.';

        return response()->json([
            'data'    => ['customer_acceptance_status' => $status],
            'message' => $message,
        ]);
    }

    /**
     * POST /api/v1/auth/orders/{ref}/reject-order-confirmation
     *
     * Authenticated customer rejects the Order Confirmation.
     * Requires an issued/sent order confirmation document.
     */
    public function rejectOrderConfirmation(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        $order = Order::where('ref', $ref)->first();

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->customer_acceptance_status === 'rejected') {
            return response()->json([
                'message' => 'Order confirmation has already been rejected.',
                'data'    => ['customer_acceptance_status' => 'rejected'],
            ], 409);
        }

        if ($order->customer_acceptance_status === 'accepted') {
            return response()->json([
                'message' => 'Order confirmation has already been accepted and cannot be rejected.',
            ], 409);
        }

        $hasAb = TradeDocument::where('order_id', $order->id)
            ->where('type', 'order_confirmation')
            ->whereIn('status', ['issued', 'sent'])
            ->exists();

        if (! $hasAb) {
            return response()->json([
                'message' => 'No order confirmation has been issued yet. Please contact Okelcor.',
                'code'    => 'no_order_confirmation',
            ], 422);
        }

        $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $order->update([
            'customer_acceptance_status'   => 'rejected',
            'customer_accepted_at'         => now(),
            'customer_accepted_ip'         => $request->ip(),
            'customer_accepted_user_agent' => $request->userAgent(),
            'customer_acceptance_note'     => $request->input('note'),
            'acceptance_token'             => null,
            'acceptance_token_expires_at'  => null,
        ]);

        $this->logOrderEvent($order, 'order_confirmation_rejected',
            'Customer rejected order confirmation for order ' . $order->ref
            . ($request->filled('note') ? '. Reason: ' . $request->input('note') : '.'));

        return response()->json([
            'data'    => ['customer_acceptance_status' => 'rejected'],
            'message' => 'Response recorded. Okelcor will be in touch shortly.',
        ]);
    }

    /**
     * GET /api/v1/documents/acceptance/{token}
     *
     * Public token-only acceptance preview (canonical route).
     * Token is the sole key — no order ref needed in URL.
     */
    public function acceptanceInfo(Request $request, string $token): JsonResponse
    {
        [$order, $error] = $this->findOrderByToken($token);

        if ($error) {
            return $error;
        }

        $document = TradeDocument::where('order_id', $order->id)
            ->where('type', 'order_confirmation')
            ->whereIn('status', ['issued', 'sent'])
            ->first();

        return response()->json([
            'data' => [
                'order_ref'                  => $order->ref,
                'order_total'                => (float) $order->total,
                'currency'                   => 'EUR',
                'customer_acceptance_status' => $order->customer_acceptance_status,
                'already_actioned'           => $order->customer_acceptance_status !== 'pending',
                'expires_at'                 => $order->acceptance_token_expires_at?->toIso8601String(),
                'document' => $document ? [
                    'type'   => $document->type,
                    'number' => $document->number,
                ] : null,
            ],
            'message' => 'success',
        ]);
    }

    /**
     * POST /api/v1/documents/acceptance/{token}/accept
     *
     * Public token-only accept (canonical route).
     * Body: { "note": "..." } (optional)
     */
    public function acceptByToken(Request $request, string $token): JsonResponse
    {
        [$order, $error] = $this->findOrderByToken($token);

        if ($error) {
            return $error;
        }

        if ($order->customer_acceptance_status === 'accepted') {
            return response()->json([
                'message' => 'Order confirmation has already been accepted.',
                'data'    => ['customer_acceptance_status' => 'accepted'],
            ], 409);
        }

        $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $order->update([
            'customer_acceptance_status'   => 'accepted',
            'customer_accepted_at'         => now(),
            'customer_accepted_ip'         => $request->ip(),
            'customer_accepted_user_agent' => $request->userAgent(),
            'customer_acceptance_note'     => $request->input('note'),
            'acceptance_token'             => null,
            'acceptance_token_expires_at'  => null,
        ]);

        $this->logOrderEvent($order, 'order_confirmation_accepted',
            'Customer accepted order confirmation via signed link.');

        return response()->json([
            'data'    => ['customer_acceptance_status' => 'accepted'],
            'message' => 'Order confirmation accepted. Okelcor will now prepare your proforma invoice.',
        ]);
    }

    /**
     * POST /api/v1/documents/acceptance/{token}/reject
     *
     * Public token-only reject (canonical route).
     * Body: { "note": "..." } (optional)
     */
    public function rejectByToken(Request $request, string $token): JsonResponse
    {
        [$order, $error] = $this->findOrderByToken($token);

        if ($error) {
            return $error;
        }

        if ($order->customer_acceptance_status === 'accepted') {
            return response()->json([
                'message' => 'Order confirmation has already been accepted and cannot be rejected.',
            ], 409);
        }

        if ($order->customer_acceptance_status === 'rejected') {
            return response()->json([
                'message' => 'Order confirmation has already been rejected.',
                'data'    => ['customer_acceptance_status' => 'rejected'],
            ], 409);
        }

        $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $order->update([
            'customer_acceptance_status'   => 'rejected',
            'customer_accepted_at'         => now(),
            'customer_accepted_ip'         => $request->ip(),
            'customer_accepted_user_agent' => $request->userAgent(),
            'customer_acceptance_note'     => $request->input('note'),
            'acceptance_token'             => null,
            'acceptance_token_expires_at'  => null,
        ]);

        $this->logOrderEvent($order, 'order_confirmation_rejected',
            'Customer rejected order confirmation via signed link.'
            . ($request->filled('note') ? ' Reason: ' . $request->input('note') : ''));

        return response()->json([
            'data'    => ['customer_acceptance_status' => 'rejected'],
            'message' => 'Response recorded. Okelcor will be in touch shortly.',
        ]);
    }

    // ── CRM-7: Proposal acceptance for logged-in customers ───────────────────

    /**
     * POST /api/v1/auth/quotes/{ref}/accept-proposal
     *
     * Authenticated customer accepts a CRM-7 proposal by quote ref_number.
     * Sets proposal_status = 'accepted'.
     * Does NOT auto-create an order — admin controls that step.
     */
    public function acceptProposal(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        $quote = QuoteRequest::where('ref_number', $ref)->first();

        if (! $quote || ! $this->customerOwnsQuote($customer, $quote)) {
            return response()->json(['message' => 'Quote not found.'], 404);
        }

        if (! in_array($quote->proposal_status, ['sent', 'ready'], true)) {
            return response()->json([
                'message' => 'No active proposal is available for acceptance.',
                'code'    => 'no_active_proposal',
                'proposal_status' => $quote->proposal_status ?? 'none',
            ], 422);
        }

        if ($quote->proposal_status === 'accepted') {
            return response()->json([
                'message' => 'Proposal has already been accepted.',
                'data'    => ['proposal_status' => 'accepted'],
            ], 409);
        }

        if ($quote->proposal_expires_at && $quote->proposal_expires_at->isPast()) {
            $quote->update(['proposal_status' => 'expired', 'proposal_acceptance_token' => null]);
            return response()->json([
                'message' => 'This proposal has expired. Please contact Okelcor for an updated proposal.',
                'code'    => 'proposal_expired',
            ], 410);
        }

        $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $quote->update([
            'proposal_status'              => 'accepted',
            'proposal_accepted_at'         => now(),
            'proposal_accepted_ip'         => $request->ip(),
            'proposal_accepted_user_agent' => $request->userAgent(),
            'proposal_acceptance_note'     => $request->input('note'),
            'proposal_acceptance_token'    => null,
        ]);

        Log::info('[proposal_accepted] Customer accepted proposal (authenticated)', [
            'event'           => 'proposal_accepted',
            'quote_ref'       => $quote->ref_number,
            'proposal_number' => $quote->proposal_number,
            'customer_id'     => $customer->id,
        ]);

        // CRM-8 timeline — a key lifecycle milestone toward buyer approval.
        CustomerTimelineService::record(
            $customer->id, 'proposal_accepted', 'Proposal accepted',
            "Customer accepted proposal {$quote->proposal_number} (quote {$quote->ref_number}).",
            ['quote_ref' => $quote->ref_number, 'proposal_number' => $quote->proposal_number]
        );

        // CRM-3B — alert the assigned owner / sales queue to convert it.
        $this->notifyProposalAccepted($quote);

        return response()->json([
            'data'    => ['proposal_status' => 'accepted'],
            'message' => 'Proposal accepted. Okelcor will proceed to create your order.',
        ]);
    }

    /**
     * POST /api/v1/auth/quotes/{ref}/reject-proposal
     *
     * Authenticated customer rejects a CRM-7 proposal by quote ref_number.
     */
    public function rejectProposal(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        $quote = QuoteRequest::where('ref_number', $ref)->first();

        if (! $quote || ! $this->customerOwnsQuote($customer, $quote)) {
            return response()->json(['message' => 'Quote not found.'], 404);
        }

        if ($quote->proposal_status === 'accepted') {
            return response()->json([
                'message' => 'Proposal has already been accepted and cannot be rejected.',
            ], 409);
        }

        if ($quote->proposal_status === 'rejected') {
            return response()->json([
                'message' => 'Proposal has already been rejected.',
                'data'    => ['proposal_status' => 'rejected'],
            ], 409);
        }

        if ($quote->proposal_status === 'converted') {
            return response()->json([
                'message' => 'This proposal has been converted to an order and cannot be rejected.',
            ], 409);
        }

        if (! in_array($quote->proposal_status, ['sent', 'ready'], true)) {
            return response()->json([
                'message' => 'No active proposal is available for rejection.',
                'code'    => 'no_active_proposal',
            ], 422);
        }

        $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $quote->update([
            'proposal_status'           => 'rejected',
            'proposal_rejected_at'      => now(),
            'proposal_rejection_reason' => $request->input('reason'),
            'proposal_acceptance_token' => null,
        ]);

        Log::info('[proposal_rejected] Customer rejected proposal (authenticated)', [
            'event'           => 'proposal_rejected',
            'quote_ref'       => $quote->ref_number,
            'proposal_number' => $quote->proposal_number,
            'customer_id'     => $customer->id,
            'reason'          => $request->input('reason'),
        ]);

        return response()->json([
            'message' => 'Proposal rejected. Okelcor will be in touch shortly.',
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Look up an order by its acceptance token.
     * Returns [Order, null] on success or [null, JsonResponse] on failure.
     *
     * @return array{0: Order|null, 1: JsonResponse|null}
     */
    private function findOrderByToken(string $token): array
    {
        if (strlen($token) !== 64) {
            return [null, response()->json([
                'message' => 'Invalid or expired acceptance link.',
                'code'    => 'invalid_token',
            ], 403)];
        }

        $order = Order::where('acceptance_token', $token)->first();

        if (! $order) {
            return [null, response()->json([
                'message' => 'Invalid or expired acceptance link.',
                'code'    => 'invalid_token',
            ], 403)];
        }

        if ($order->acceptance_token_expires_at && $order->acceptance_token_expires_at->isPast()) {
            return [null, response()->json([
                'message' => 'This acceptance link has expired. Please contact Okelcor for a new one.',
                'code'    => 'token_expired',
            ], 403)];
        }

        return [$order, null];
    }

    private function customerOwnsQuote($customer, QuoteRequest $quote): bool
    {
        $byId    = $quote->customer_id !== null && $quote->customer_id === $customer->id;
        $byEmail = strtolower($quote->email) === strtolower($customer->email);

        return $byId || $byEmail;
    }

    /**
     * CRM-3B — notify the assigned owner (or the sales/admin queue) that a
     * proposal was accepted and is awaiting conversion to an order.
     */
    private function notifyProposalAccepted(QuoteRequest $quote): void
    {
        $title = 'Proposal accepted';
        $body  = sprintf(
            'Proposal %s from %s was accepted — ready to convert to an order.',
            $quote->proposal_number,
            $quote->company_name ?: $quote->full_name
        );
        $url = "/admin/quotes/{$quote->id}";

        if ($quote->assigned_to) {
            AdminNotificationService::notifyUser(
                adminUserId: (int) $quote->assigned_to,
                type:        'proposal_accepted',
                title:       $title,
                body:        $body,
                actionUrl:   $url,
                severity:    'success',
                relatedType: 'quote_request',
                relatedId:   $quote->id,
                metadata:    ['ref_number' => $quote->ref_number, 'proposal_number' => $quote->proposal_number],
            );

            return;
        }

        AdminNotificationService::notifyPermission(
            permission:  'quotes.manage',
            type:        'proposal_accepted',
            title:       $title,
            body:        $body,
            actionUrl:   $url,
            severity:    'success',
            relatedType: 'quote_request',
            relatedId:   $quote->id,
            metadata:    ['ref_number' => $quote->ref_number, 'proposal_number' => $quote->proposal_number],
        );
    }

    private function logQuoteEvent(QuoteRequest $quote, string $action, string $note): void
    {
        if (! $quote->order_id) {
            return;
        }

        try {
            OrderLog::create([
                'order_id'  => $quote->order_id,
                'order_ref' => $quote->ref_number,
                'action'    => $action,
                'notes'     => $note,
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (quote acceptance)', [
                'quote_ref' => $quote->ref_number,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function logOrderEvent(Order $order, string $action, string $note): void
    {
        try {
            OrderLog::create([
                'order_id'  => $order->id,
                'order_ref' => $order->ref,
                'action'    => $action,
                'notes'     => $note,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (order acceptance)', [
                'order_ref' => $order->ref,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
