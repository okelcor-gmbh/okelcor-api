<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * order_logs.action is a MySQL ENUM. Several already-shipped features write
 * action values that were never added to it — 'financial_corrected'
 * (AdminOrderController::patchFinancials), the whole revision-request/
 * approve/reject workflow (AdminOrderFinancialsController), and 'created'
 * (the historical-order admin-creation endpoint). Because writeLog() is
 * wrapped in try/catch everywhere, none of these crashed — they've just been
 * silently failing to write their audit trail entry. Found while adding the
 * order-item-editing feature below, which writes to the same column.
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
    ];

    private const NEW = [
        // Pre-existing gaps (already shipped, never had a matching enum value)
        'created',
        'financial_corrected',
        'financial_revision_requested',
        'financial_revision_approved',
        'financial_revision_rejected',
        // This session — order item corrections
        'item_corrected',
        'item_added',
        'item_removed',
    ];

    public function up(): void
    {
        $values = implode(',', array_map(fn ($v) => "'{$v}'", [...self::PREVIOUS, ...self::NEW]));
        DB::statement("ALTER TABLE order_logs MODIFY COLUMN action ENUM({$values}) NOT NULL");
    }

    public function down(): void
    {
        $values = implode(',', array_map(fn ($v) => "'{$v}'", self::PREVIOUS));
        DB::statement("ALTER TABLE order_logs MODIFY COLUMN action ENUM({$values}) NOT NULL");
    }
};
