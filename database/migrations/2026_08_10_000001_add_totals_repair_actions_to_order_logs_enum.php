<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * order_logs.action is a MySQL ENUM (see 2026_07_15_000001 for the same
 * widening pattern and why the FULL list is required every time, not just the
 * new addition). Adds the two values the order-total repair tooling writes.
 *
 * Learned the hard way: `orders:repair-totals --fix` was run before this
 * existed and MySQL rejected the insert with "Data truncated for column
 * 'action'" — after the order row had already been updated. The commands now
 * write the order and its log in one transaction so that cannot recur.
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
    ];

    private const NEW = [
        'totals_repaired',
        'totals_restored',
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

        $values = implode(',', array_map(fn ($v) => "'{$v}'", self::PREVIOUS));
        DB::statement("ALTER TABLE order_logs MODIFY COLUMN action ENUM({$values}) NOT NULL");
    }
};
