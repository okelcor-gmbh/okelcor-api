<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Putting an order's payment state back to what is actually true.
 *
 * Every other path through the payment ladder moves in one direction. Deposit
 * requested, deposit paid, balance paid, shipment released — each guarded, each
 * refusing to run out of order, which is right while an order is moving
 * forward. The gap that leaves is the one the order manager reported twice:
 * when an order arrives at "paid" without anyone at Okelcor having decided it,
 * nothing in the product can put it back. She has to ask a developer, and until
 * one is free the buyer's portal says he has paid for something he has not.
 *
 * So this is deliberately the only backwards path, and it is narrow:
 *
 *  - It never moves an order FORWARD. Recording that money arrived stays with
 *    the milestone actions, which e-mail the customer and stamp who confirmed
 *    it. A correction tool that could also mark a deposit received would become
 *    the quick way round those, and then the ladder's guards would mean nothing.
 *  - It never touches a Stripe order. The gateway owns that payment state; a
 *    figure typed here would just disagree with Stripe until someone noticed.
 *  - It never e-mails the customer. Correcting our own record is not an event
 *    in his order, and a "your payment status changed" for a payment that never
 *    happened is exactly the confusion this is meant to end.
 *  - It always demands a reason and always writes one audit row. The whole
 *    reason this is safe to hand to an order manager is that it cannot be done
 *    quietly.
 *
 * Timestamps for stages being undone are cleared, because `deposit_paid_at` is
 * a claim that money arrived on that date and leaving it behind a rolled-back
 * stage leaves the claim standing. The `*_email_sent_at` columns are NOT
 * cleared: an e-mail that went out went out, and rewriting that would lose the
 * one record of what the customer was actually told.
 */
class PaymentStateCorrectionService
{
    /** The ladder, in order. Index is the comparison — see `stageIndex()`. */
    public const STAGES = [
        'pending_proforma',
        'deposit_requested',
        'deposit_paid',
        'balance_due',
        'balance_paid',
        'shipment_released',
    ];

    /**
     * A null stage is an order that predates milestones entirely; it sits at
     * the resting state rather than nowhere, which is how every other reader
     * in this codebase treats it.
     */
    public function stageIndex(?string $stage): int
    {
        $index = array_search($stage ?? 'pending_proforma', self::STAGES, true);

        return $index === false ? 0 : $index;
    }

    /**
     * Why this correction may not be made, or null if it may.
     *
     * Returned rather than thrown so the controller and the console command can
     * each say it in their own register without either re-deriving the rules.
     *
     * @return array{message: string, code: string}|null
     */
    public function refuse(Order $order, string $targetStage, bool $resetPaymentStatus): ?array
    {
        if (! in_array($targetStage, self::STAGES, true)) {
            return [
                'message' => "'{$targetStage}' is not a payment stage.",
                'code'    => 'unknown_payment_stage',
            ];
        }

        if ($order->payment_method === 'stripe') {
            return [
                'message' => 'This order is paid through Stripe. Its payment state is set by the gateway, not by hand.',
                'code'    => 'gateway_managed_payment',
            ];
        }

        $current = $this->stageIndex($order->payment_stage);
        $target  = $this->stageIndex($targetStage);

        if ($target > $current) {
            return [
                'message' => 'This corrects a payment state that is further along than it should be. '
                    . 'To record that money has arrived, use the payment milestone actions — they notify '
                    . 'the customer and record who confirmed it.',
                'code'    => 'use_the_milestone_actions',
            ];
        }

        if ($target === $current && ! ($resetPaymentStatus && $order->payment_status !== 'pending')) {
            return [
                'message' => 'This order is already in that state — nothing to correct.',
                'code'    => 'nothing_to_correct',
            ];
        }

        return null;
    }

    /**
     * Apply the correction and record it. Assumes `refuse()` returned null.
     *
     * @return array{
     *     stage_from: string, stage_to: string,
     *     status_from: string, status_to: string,
     *     cleared: array<int, string>
     * }
     */
    public function apply(
        Order $order,
        string $targetStage,
        bool $resetPaymentStatus,
        string $reason,
        ?AdminUser $admin = null,
        ?string $ip = null,
        string $via = 'admin panel',
    ): array {
        $stageFrom  = $order->payment_stage ?? 'pending_proforma';
        $statusFrom = $order->payment_status;
        $target     = $this->stageIndex($targetStage);

        $changes = ['payment_stage' => $targetStage];
        $cleared = [];

        // A stage being rolled back takes its evidence with it. Leaving
        // `deposit_paid_at` behind a stage of `deposit_requested` would leave a
        // date asserting the deposit arrived, which is the claim being withdrawn.
        if ($target < $this->stageIndex('deposit_paid') && $order->deposit_paid_at !== null) {
            $changes['deposit_paid_at']      = null;
            $changes['deposit_confirmed_by'] = null;
            $cleared[] = 'deposit_paid_at';
        }

        if ($target < $this->stageIndex('balance_paid') && $order->balance_paid_at !== null) {
            $changes['balance_paid_at']      = null;
            $changes['balance_confirmed_by'] = null;
            $cleared[] = 'balance_paid_at';
        }

        if ($target < $this->stageIndex('shipment_released') && $order->shipment_released_at !== null) {
            $changes['shipment_released_at']  = null;
            $changes['shipment_released_by']  = null;
            $changes['shipment_release_note'] = null;
            $cleared[] = 'shipment_released_at';
        }

        // The deposit/balance split is arithmetic on the order total, not a
        // claim that anything was paid, and it is what the buyer was quoted.
        // Kept even at the resting stage, where nothing renders it anyway.

        $statusTo = $resetPaymentStatus ? 'pending' : $statusFrom;

        if ($resetPaymentStatus) {
            $changes['payment_status'] = 'pending';
        }

        DB::transaction(function () use ($order, $changes, $stageFrom, $targetStage, $statusFrom, $statusTo, $reason, $admin, $ip, $via, $cleared) {
            $order->update($changes);

            // Unlike every other order log write in this codebase this one is
            // NOT inside a try/catch. A correction that silently failed to
            // record itself would be the one thing worse than the state it
            // corrects: the buyer's payment state changed and nothing says who
            // did it or why. Rolling back is the honest outcome — and the
            // action is in OrderLog::ACTIONS, which a test enforces.
            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email ?? "console:{$via}",
                'action'           => 'payment_state_corrected',
                'old_value'        => "{$stageFrom} / {$statusFrom}",
                'new_value'        => "{$targetStage} / {$statusTo}",
                'notes'            => implode(' | ', array_filter([
                    'Corrected via ' . $via . '.',
                    $cleared ? 'Cleared: ' . implode(', ', $cleared) . '.' : null,
                    'Reason: ' . $reason,
                ])),
                'ip_address'       => $ip,
            ]);
        });

        Log::info('Order payment state corrected', [
            'order_ref'  => $order->ref,
            'stage_from' => $stageFrom,
            'stage_to'   => $targetStage,
            'status_from' => $statusFrom,
            'status_to'  => $statusTo,
            'by'         => $admin?->email ?? $via,
        ]);

        return [
            'stage_from'  => $stageFrom,
            'stage_to'    => $targetStage,
            'status_from' => $statusFrom,
            'status_to'   => $statusTo,
            'cleared'     => $cleared,
        ];
    }

    /**
     * Why this order's "paid" appearance is not backed by anything, or null if
     * it is.
     *
     * An order presents as paid through two different columns — `payment_status`
     * and the late stages of `payment_stage` — and each has a legitimate way of
     * getting there and at least one accidental one. What separates them is
     * whether a record exists of a person, a gateway or a marketplace confirming
     * receipt. Where none does, the state was derived rather than observed, and
     * derived is how a buyer ends up looking at a deposit he never paid.
     *
     * Used by the audit sweep. It reports rather than repairs: nothing here can
     * tell whether the money actually arrived, only whether we wrote down that
     * it did, and a rule that guessed would eventually guess against the bank.
     */
    public function unevidencedReason(Order $order): ?string
    {
        $presentsAsPaid = $order->payment_status === 'paid'
            || in_array($order->payment_stage, ['deposit_paid', 'balance_due', 'balance_paid', 'shipment_released'], true);

        if (! $presentsAsPaid) {
            return null;
        }

        // Stripe and eBay both settle outside this database and write the
        // status from a source that genuinely knows.
        if ($order->payment_method === 'stripe' || $order->payment_session_id) {
            return null;
        }

        if ($order->source === 'ebay') {
            return null;
        }

        // Someone pressed a milestone button: the stamp is the evidence.
        if ($order->deposit_paid_at !== null || $order->balance_paid_at !== null) {
            return null;
        }

        $confirmedByHand = OrderLog::where('order_id', $order->id)
            ->whereIn('action', ['deposit_paid', 'balance_paid', 'payment_status_changed', 'payment_state_corrected'])
            ->exists();

        if ($confirmedByHand) {
            return null;
        }

        return $order->payment_status === 'paid'
            ? 'payment_status is "paid" with nothing recording who confirmed it'
            : "payment_stage is \"{$order->payment_stage}\" with no deposit or balance confirmation behind it";
    }
}
