<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLog;
use App\Services\PaymentMilestoneEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminOrderPaymentMilestoneController extends Controller
{
    public function __construct(private PaymentMilestoneEmailService $emailService) {}

    /**
     * POST /api/v1/admin/orders/{id}/payment-milestones/deposit-paid
     *
     * Confirm the customer's deposit has arrived. Unlocks CI, PL, and
     * shipment document uploads.
     */
    public function markDepositPaid(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes'             => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $order = Order::findOrFail($id);
        $admin = $request->user();

        if ($order->payment_stage !== 'deposit_requested') {
            return response()->json([
                'message'       => "Deposit can only be confirmed when payment stage is 'deposit_requested'. Current: {$order->payment_stage}",
                'code'          => 'invalid_payment_stage',
                'payment_stage' => $order->payment_stage,
            ], 409);
        }

        $order->update([
            'payment_stage'        => 'deposit_paid',
            'deposit_paid_at'      => now(),
            'deposit_confirmed_by' => $admin?->id,
        ]);

        $this->log($request, $order, 'deposit_paid', [
            'new_value' => 'deposit_paid',
            'notes'     => implode(' | ', array_filter([
                $request->filled('payment_reference') ? 'Ref: ' . $request->payment_reference : null,
                $request->filled('notes') ? $request->notes : null,
                'Deposit confirmed.',
            ])),
        ]);

        $emailSent = $this->emailService->send($order, 'deposit_paid');

        return response()->json([
            'data'         => $this->formatMilestones($order),
            'message'      => 'Deposit confirmed. Commercial invoice and packing list can now be generated.',
            'email_sent'   => $emailSent,
            'email_warning' => $emailSent ? null : 'Customer notification email could not be sent.',
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/payment-milestones/balance-due
     *
     * Explicitly mark balance as outstanding (optional step between deposit_paid
     * and balance_paid — useful to record that a balance invoice has been issued).
     */
    public function markBalanceDue(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $order = Order::findOrFail($id);

        if ($order->payment_stage !== 'deposit_paid') {
            return response()->json([
                'message'       => "Balance due can only be set when stage is 'deposit_paid'. Current: {$order->payment_stage}",
                'code'          => 'invalid_payment_stage',
                'payment_stage' => $order->payment_stage,
            ], 409);
        }

        $order->update(['payment_stage' => 'balance_due']);

        $this->log($request, $order, 'balance_due', [
            'new_value' => 'balance_due',
            'notes'     => $request->filled('notes') ? $request->notes : 'Balance marked as due.',
        ]);

        $emailSent = $this->emailService->send($order, 'balance_due');

        return response()->json([
            'data'          => $this->formatMilestones($order),
            'message'       => 'Balance marked as due.',
            'email_sent'    => $emailSent,
            'email_warning' => $emailSent ? null : 'Customer notification email could not be sent.',
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/payment-milestones/balance-paid
     *
     * Confirm full balance received. Unlocks shipment release.
     * Accepts from deposit_paid (skipping balance_due) or balance_due.
     */
    public function markBalancePaid(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes'             => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $order = Order::findOrFail($id);
        $admin = $request->user();

        if (! in_array($order->payment_stage, ['deposit_paid', 'balance_due'], true)) {
            return response()->json([
                'message'       => "Balance can only be confirmed when stage is 'deposit_paid' or 'balance_due'. Current: {$order->payment_stage}",
                'code'          => 'invalid_payment_stage',
                'payment_stage' => $order->payment_stage,
            ], 409);
        }

        $order->update([
            'payment_stage'        => 'balance_paid',
            'balance_paid_at'      => now(),
            'balance_confirmed_by' => $admin?->id,
        ]);

        $this->log($request, $order, 'balance_paid', [
            'new_value' => 'balance_paid',
            'notes'     => implode(' | ', array_filter([
                $request->filled('payment_reference') ? 'Ref: ' . $request->payment_reference : null,
                $request->filled('notes') ? $request->notes : null,
                'Balance payment confirmed.',
            ])),
        ]);

        $emailSent = $this->emailService->send($order, 'balance_paid');

        return response()->json([
            'data'          => $this->formatMilestones($order),
            'message'       => 'Balance payment confirmed. Shipment can now be released.',
            'email_sent'    => $emailSent,
            'email_warning' => $emailSent ? null : 'Customer notification email could not be sent.',
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/payment-milestones/release-shipment
     *
     * Release shipment after full payment. Unlocks delivery note generation.
     */
    public function releaseShipment(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'release_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $order = Order::findOrFail($id);
        $admin = $request->user();

        if ($order->payment_stage !== 'balance_paid') {
            return response()->json([
                'message'       => "Shipment can only be released when stage is 'balance_paid'. Current: {$order->payment_stage}",
                'code'          => 'invalid_payment_stage',
                'payment_stage' => $order->payment_stage,
            ], 409);
        }

        $order->update([
            'payment_stage'         => 'shipment_released',
            'shipment_released_at'  => now(),
            'shipment_released_by'  => $admin?->id,
            'shipment_release_note' => $request->input('release_note'),
        ]);

        $this->log($request, $order, 'shipment_released', [
            'new_value' => 'shipment_released',
            'notes'     => $request->filled('release_note')
                ? 'Release note: ' . $request->release_note
                : 'Shipment released.',
        ]);

        $emailSent = $this->emailService->send($order, 'shipment_released');

        return response()->json([
            'data'          => $this->formatMilestones($order),
            'message'       => 'Shipment released. Delivery note can now be generated.',
            'email_sent'    => $emailSent,
            'email_warning' => $emailSent ? null : 'Customer notification email could not be sent.',
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/payment-milestones/resend-email
     *
     * Resend a specific milestone notification email regardless of whether it
     * was previously sent. Always overwrites the sent_at timestamp on success.
     *
     * Body: { "stage": "deposit_requested" }
     */
    public function resendEmail(Request $request, int $id): JsonResponse
    {
        $validStages = ['deposit_requested', 'deposit_paid', 'balance_due', 'balance_paid', 'shipment_released'];

        $request->validate([
            'stage' => ['required', 'string', 'in:' . implode(',', $validStages)],
        ]);

        $order = Order::findOrFail($id);
        $stage = $request->input('stage');

        if (! $order->customer_email) {
            return response()->json([
                'message' => 'This order has no customer email address.',
            ], 422);
        }

        $sent = $this->emailService->send($order, $stage, isResend: true);

        if (! $sent) {
            return response()->json([
                'message' => "Failed to send {$stage} email. Check application logs.",
                'code'    => 'email_send_failed',
            ], 500);
        }

        $sentAtColumn = PaymentMilestoneEmailService::sentAtColumn($stage);

        return response()->json([
            'data'    => [
                'stage'   => $stage,
                'sent_at' => $order->fresh()->{$sentAtColumn}?->toIso8601String(),
            ],
            'message' => "Notification email for stage '{$stage}' resent to {$order->customer_email}.",
        ]);
    }

    // -------------------------------------------------------------------------

    private function formatMilestones(Order $order): array
    {
        return [
            'id'                                   => $order->id,
            'order_ref'                            => $order->ref,
            'payment_stage'                        => $order->payment_stage,
            'deposit_percent'                      => (float) $order->deposit_percent,
            'deposit_amount'                       => $order->deposit_amount !== null ? (float) $order->deposit_amount : null,
            'deposit_paid_at'                      => $order->deposit_paid_at?->toIso8601String(),
            'balance_amount'                       => $order->balance_amount !== null ? (float) $order->balance_amount : null,
            'balance_paid_at'                      => $order->balance_paid_at?->toIso8601String(),
            'shipment_released_at'                 => $order->shipment_released_at?->toIso8601String(),
            'shipment_release_note'                => $order->shipment_release_note,
            'deposit_requested_email_sent_at'      => $order->deposit_requested_email_sent_at?->toIso8601String(),
            'deposit_paid_email_sent_at'           => $order->deposit_paid_email_sent_at?->toIso8601String(),
            'balance_due_email_sent_at'            => $order->balance_due_email_sent_at?->toIso8601String(),
            'balance_paid_email_sent_at'           => $order->balance_paid_email_sent_at?->toIso8601String(),
            'shipment_released_email_sent_at'      => $order->shipment_released_email_sent_at?->toIso8601String(),
        ];
    }

    private function log(Request $request, Order $order, string $action, array $extra = []): void
    {
        try {
            $admin = $request->user();
            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => $action,
                'old_value'        => $extra['old_value'] ?? null,
                'new_value'        => $extra['new_value'] ?? null,
                'notes'            => $extra['notes'] ?? null,
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (payment milestone)', [
                'order_ref' => $order->ref,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
