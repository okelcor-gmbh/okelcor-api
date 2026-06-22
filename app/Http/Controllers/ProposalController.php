<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use App\Services\AdminNotificationService;
use App\Services\CustomerTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public token-based proposal acceptance/rejection.
 * No authentication required — token is the sole credential.
 *
 * Routes:
 *   GET  /api/v1/proposals/{token}         — show safe proposal summary
 *   POST /api/v1/proposals/{token}/accept  — customer accepts
 *   POST /api/v1/proposals/{token}/reject  — customer rejects
 */
class ProposalController extends Controller
{
    /**
     * GET /api/v1/proposals/{token}
     *
     * Returns safe proposal info — no internal notes, no IP/UA, no admin data.
     * Auto-marks expired proposals.
     */
    public function show(string $token): JsonResponse
    {
        [$quote, $error] = $this->findByToken($token);
        if ($error) {
            return $error;
        }

        $this->autoExpireIfNeeded($quote);

        return response()->json([
            'data'    => $this->safePayload($quote->fresh()),
            'message' => 'success',
        ]);
    }

    /**
     * POST /api/v1/proposals/{token}/accept
     *
     * Customer accepts the proposal.
     * Sets proposal_status = 'accepted', records metadata.
     * Does NOT auto-convert to order — admin controls that step.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        [$quote, $error] = $this->findByToken($token);
        if ($error) {
            return $error;
        }

        $this->autoExpireIfNeeded($quote);
        $quote->refresh();

        if ($quote->proposal_status === 'accepted') {
            return response()->json([
                'message' => 'This proposal has already been accepted.',
                'data'    => ['proposal_status' => 'accepted'],
            ], 409);
        }

        if ($quote->proposal_status === 'converted') {
            return response()->json([
                'message' => 'This proposal has already been converted to an order.',
                'data'    => ['proposal_status' => 'converted'],
            ], 409);
        }

        if ($quote->proposal_status === 'expired') {
            return response()->json([
                'message' => 'This proposal has expired. Please contact Okelcor for an updated proposal.',
                'code'    => 'proposal_expired',
            ], 410);
        }

        if (! in_array($quote->proposal_status, ['sent', 'draft', 'ready'], true)) {
            return response()->json([
                'message' => 'This proposal cannot be accepted in its current state.',
                'code'    => 'proposal_invalid_state',
            ], 422);
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
            'proposal_acceptance_token'    => null, // invalidate token after use
        ]);

        Log::info('[proposal_accepted] Customer accepted proposal via token', [
            'event'           => 'proposal_accepted',
            'quote_ref'       => $quote->ref_number,
            'proposal_number' => $quote->proposal_number,
            'ip'              => $request->ip(),
        ]);

        $this->logCommunication($quote, 'system', 'inbound',
            "Proposal {$quote->proposal_number} accepted",
            'Customer accepted the proposal via the acceptance link.');

        $this->notifyProposalAccepted($quote);

        // CRM-8 timeline — resolve the customer this quote belongs to.
        $customerId = $quote->customer_id
            ?? Customer::where('email', $quote->email)->value('id');
        if ($customerId) {
            CustomerTimelineService::record(
                $customerId, 'proposal_accepted', 'Proposal accepted',
                "Customer accepted proposal {$quote->proposal_number} (quote {$quote->ref_number}) via link.",
                ['quote_ref' => $quote->ref_number, 'proposal_number' => $quote->proposal_number]
            );
        }

        return response()->json([
            'data'    => ['proposal_status' => 'accepted'],
            'message' => 'Thank you — your proposal has been accepted. Okelcor will be in touch to finalise your order.',
        ]);
    }

    /**
     * POST /api/v1/proposals/{token}/reject
     *
     * Customer rejects the proposal with an optional reason.
     */
    public function reject(Request $request, string $token): JsonResponse
    {
        [$quote, $error] = $this->findByToken($token);
        if ($error) {
            return $error;
        }

        $this->autoExpireIfNeeded($quote);
        $quote->refresh();

        if ($quote->proposal_status === 'rejected') {
            return response()->json([
                'message' => 'This proposal has already been rejected.',
                'data'    => ['proposal_status' => 'rejected'],
            ], 409);
        }

        if ($quote->proposal_status === 'accepted') {
            return response()->json([
                'message' => 'This proposal has already been accepted and cannot be rejected.',
            ], 409);
        }

        if ($quote->proposal_status === 'converted') {
            return response()->json([
                'message' => 'This proposal has been converted to an order and cannot be rejected.',
            ], 409);
        }

        if ($quote->proposal_status === 'expired') {
            return response()->json([
                'message' => 'This proposal has expired.',
                'code'    => 'proposal_expired',
            ], 410);
        }

        $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $quote->update([
            'proposal_status'              => 'rejected',
            'proposal_rejected_at'         => now(),
            'proposal_rejection_reason'    => $request->input('reason'),
            'proposal_accepted_ip'         => $request->ip(),
            'proposal_accepted_user_agent' => $request->userAgent(),
            'proposal_acceptance_token'    => null,
        ]);

        Log::info('[proposal_rejected] Customer rejected proposal via token', [
            'event'           => 'proposal_rejected',
            'quote_ref'       => $quote->ref_number,
            'proposal_number' => $quote->proposal_number,
            'reason'          => $request->input('reason'),
            'ip'              => $request->ip(),
        ]);

        $this->logCommunication($quote, 'system', 'inbound',
            "Proposal {$quote->proposal_number} rejected",
            'Customer rejected the proposal via the acceptance link.'
            . ($request->filled('reason') ? ' Reason: ' . $request->input('reason') : ''));

        return response()->json([
            'message' => 'Your response has been recorded. Okelcor will be in touch shortly.',
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Look up a quote by proposal_acceptance_token.
     * Returns [QuoteRequest, null] on success or [null, JsonResponse] on failure.
     *
     * @return array{0: QuoteRequest|null, 1: JsonResponse|null}
     */
    private function findByToken(string $token): array
    {
        if (strlen($token) !== 64) {
            return [null, response()->json([
                'message' => 'Invalid or expired proposal link.',
                'code'    => 'invalid_token',
            ], 403)];
        }

        $quote = QuoteRequest::where('proposal_acceptance_token', $token)->first();

        if (! $quote) {
            return [null, response()->json([
                'message' => 'Invalid or expired proposal link.',
                'code'    => 'invalid_token',
            ], 403)];
        }

        return [$quote, null];
    }

    /**
     * If the proposal has passed its expiry date and is still in 'sent' state,
     * automatically advance it to 'expired'.
     */
    private function autoExpireIfNeeded(QuoteRequest $quote): void
    {
        if (
            $quote->proposal_status === 'sent'
            && $quote->proposal_expires_at
            && $quote->proposal_expires_at->isPast()
        ) {
            $quote->update([
                'proposal_status'           => 'expired',
                'proposal_acceptance_token' => null,
            ]);

            Log::info('[proposal_expired] Proposal auto-expired', [
                'event'           => 'proposal_expired',
                'quote_ref'       => $quote->ref_number,
                'proposal_number' => $quote->proposal_number,
            ]);
        }
    }

    /**
     * Return only public-safe fields — no internal notes, no IP/UA, no admin data.
     */
    private function safePayload(QuoteRequest $quote): array
    {
        return [
            'proposal_number'   => $quote->proposal_number,
            'quote_ref'         => $quote->ref_number,
            'company_name'      => $quote->company_name,
            'full_name'         => $quote->full_name,
            'proposal_status'   => $quote->proposal_status ?? 'none',
            'proposal_total'    => $quote->proposal_total ? (float) $quote->proposal_total : null,
            'proposal_currency' => $quote->proposal_currency ?? 'EUR',
            'proposal_items'    => $quote->proposal_items ?? [],
            'expires_at'        => $quote->proposal_expires_at?->toIso8601String(),
            'already_actioned'  => in_array($quote->proposal_status, ['accepted', 'rejected', 'expired', 'converted'], true),
        ];
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

    private function logCommunication(
        QuoteRequest $quote,
        string $type,
        string $direction,
        string $subject,
        string $body
    ): void {
        try {
            CustomerCommunication::create([
                'quote_request_id' => $quote->id,
                'customer_id'      => $quote->customer_id,
                'type'             => $type,
                'direction'        => $direction,
                'subject'          => $subject,
                'body'             => $body,
                'status'           => 'completed',
                'completed_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CustomerCommunication write failed (public proposal)', [
                'quote_ref' => $quote->ref_number,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
