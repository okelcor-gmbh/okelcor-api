<?php

namespace App\Services;

use App\Mail\PaymentMilestoneUpdatedMail;
use App\Models\Order;
use App\Models\OrderLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentMilestoneEmailService
{
    /** Sentinel column for each stage — null means "not yet sent". */
    private const SENT_AT_COLUMN = [
        'deposit_requested' => 'deposit_requested_email_sent_at',
        'deposit_paid'      => 'deposit_paid_email_sent_at',
        'balance_due'       => 'balance_due_email_sent_at',
        'balance_paid'      => 'balance_paid_email_sent_at',
        'shipment_released' => 'shipment_released_email_sent_at',
    ];

    /**
     * Send a milestone notification email.
     *
     * @param  bool  $isResend  Skip duplicate guard when true (explicit admin resend).
     * @return bool             True if mail was dispatched, false if skipped or failed.
     */
    public function send(Order $order, string $stage, bool $isResend = false): bool
    {
        $column = self::SENT_AT_COLUMN[$stage] ?? null;

        if (! $column) {
            Log::warning('PaymentMilestoneEmailService: unknown stage', [
                'order_ref' => $order->ref,
                'stage'     => $stage,
            ]);
            return false;
        }

        if (! $order->customer_email) {
            return false;
        }

        // Duplicate guard — skip if already sent unless this is an explicit resend
        if (! $isResend && $order->{$column} !== null) {
            return false;
        }

        try {
            Mail::to($order->customer_email)->send(new PaymentMilestoneUpdatedMail($order, $stage));

            $order->update([$column => now()]);

            $this->writeLog($order, 'payment_milestone_email_sent', $stage);

            return true;
        } catch (\Throwable $e) {
            Log::error('Payment milestone email failed', [
                'order_ref' => $order->ref,
                'stage'     => $stage,
                'error'     => $e->getMessage(),
            ]);

            $this->writeLog($order, 'payment_milestone_email_failed', $stage, $e->getMessage());

            return false;
        }
    }

    /**
     * Return the sent-at timestamp column name for a given stage.
     * Returns null for unknown stages.
     */
    public static function sentAtColumn(string $stage): ?string
    {
        return self::SENT_AT_COLUMN[$stage] ?? null;
    }

    private function writeLog(Order $order, string $action, string $stage, ?string $errorMessage = null): void
    {
        try {
            OrderLog::create([
                'order_id'  => $order->id,
                'order_ref' => $order->ref,
                'action'    => $action,
                'new_value' => $stage,
                'notes'     => $errorMessage ? "Stage: {$stage}. Error: {$errorMessage}" : "Stage: {$stage}",
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (payment milestone email)', [
                'order_ref' => $order->ref,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
