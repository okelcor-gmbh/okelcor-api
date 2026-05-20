<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\QuoteRequest;
use App\Models\TradeDocument;
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

    // -------------------------------------------------------------------------

    private function customerOwnsQuote($customer, QuoteRequest $quote): bool
    {
        $byId    = $quote->customer_id !== null && $quote->customer_id === $customer->id;
        $byEmail = strtolower($quote->email) === strtolower($customer->email);

        return $byId || $byEmail;
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
