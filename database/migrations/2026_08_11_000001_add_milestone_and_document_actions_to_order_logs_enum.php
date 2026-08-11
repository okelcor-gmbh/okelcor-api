<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * order_logs.action is a MySQL ENUM. Same widening pattern as 2026_07_15_000001,
 * 2026_07_17_120845 and 2026_08_10_000001: the FULL value list is required on
 * every ALTER, not just the addition.
 *
 * This one is a backlog, not a feature. Every value below is already written by
 * shipped code and has been rejected by MySQL on production ever since —
 * silently, because all of these writes sit behind a try/catch that logs a
 * warning and carries on. The audit rows were never created and nobody noticed,
 * which is precisely the failure mode an append-only trail is supposed to make
 * impossible.
 *
 * Found while adding 'deposit_requested' in Session 76: the four milestone
 * actions next to it (deposit_paid, balance_due, balance_paid,
 * shipment_released) had never been in the ENUM either — so the payment
 * milestone history, the one thing that evidences who confirmed a customer's
 * money had arrived, does not exist on production for any order.
 *
 * Values grouped by where they are written:
 *   AdminOrderPaymentMilestoneController — deposit_requested, deposit_paid,
 *       balance_due, balance_paid, shipment_released
 *   PaymentMilestoneEmailService         — payment_milestone_email_sent/_failed
 *   AdminEuDeclarationController         — declaration_acknowledged
 *   AdminTradeDocumentController         — document_superseded,
 *       document_generation_blocked_payment_stage
 *   TradeDocumentController              — proforma_signed_returned
 *
 * Skipped on non-MySQL drivers so the sqlite test harness is unaffected.
 */
return new class extends Migration
{
    private const PREVIOUS = [
        'status_changed', 'cancelled', 'deleted', 'tracking_updated', 'payment_status_changed',
        'document_generated', 'document_uploaded', 'document_deleted', 'document_sent', 'document_voided',
        'order_confirmation_accepted', 'order_confirmation_rejected',
        'customer_proposal_accepted', 'customer_proposal_rejected',
        'acceptance_link_generated', 'acceptance_request_sent',
        'proforma_generation_blocked_no_acceptance', 'premature_proforma_superseded',
        'order_confirmation_auto_generated',
        'created',
        'financial_corrected',
        'financial_revision_requested',
        'financial_revision_approved',
        'financial_revision_rejected',
        'item_corrected',
        'item_added',
        'item_removed',
        'currency_converted',
        'totals_repaired',
        'totals_restored',
    ];

    private const NEW = [
        'deposit_requested',
        'deposit_paid',
        'balance_due',
        'balance_paid',
        'shipment_released',
        'payment_milestone_email_sent',
        'payment_milestone_email_failed',
        'declaration_acknowledged',
        'document_superseded',
        'document_generation_blocked_payment_stage',
        'proforma_signed_returned',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = implode(',', array_map(fn ($v) => "'{$v}'", [...self::PREVIOUS, ...self::NEW]));
        DB::statement("ALTER TABLE order_logs MODIFY COLUMN action ENUM({$values}) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Rows carrying a value being removed would be truncated by the ALTER.
        // Nothing sensible to migrate them to, so refuse rather than corrupt an
        // append-only audit trail.
        $orphans = DB::table('order_logs')->whereIn('action', self::NEW)->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$orphans} order_logs row(s) use an action this migration added. "
                . 'Reverting the ENUM would silently truncate them.'
            );
        }

        $values = implode(',', array_map(fn ($v) => "'{$v}'", self::PREVIOUS));
        DB::statement("ALTER TABLE order_logs MODIFY COLUMN action ENUM({$values}) NOT NULL");
    }
};
