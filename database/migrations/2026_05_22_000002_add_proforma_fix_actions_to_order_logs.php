<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend order_logs.action enum with premature-proforma correction actions
     * added by the DOC-FLOW-FIX (stop auto-PI before customer acceptance).
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE order_logs
            MODIFY COLUMN action ENUM(
                'status_changed',
                'cancelled',
                'deleted',
                'tracking_updated',
                'payment_status_changed',
                'document_generated',
                'document_uploaded',
                'document_deleted',
                'document_sent',
                'document_voided',
                'order_confirmation_accepted',
                'order_confirmation_rejected',
                'customer_proposal_accepted',
                'customer_proposal_rejected',
                'acceptance_link_generated',
                'acceptance_request_sent',
                'proforma_generation_blocked_no_acceptance',
                'premature_proforma_superseded',
                'order_confirmation_auto_generated'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE order_logs
            MODIFY COLUMN action ENUM(
                'status_changed',
                'cancelled',
                'deleted',
                'tracking_updated',
                'payment_status_changed',
                'document_generated',
                'document_uploaded',
                'document_deleted',
                'document_sent',
                'document_voided',
                'order_confirmation_accepted',
                'order_confirmation_rejected',
                'customer_proposal_accepted',
                'customer_proposal_rejected',
                'acceptance_link_generated',
                'acceptance_request_sent',
                'proforma_generation_blocked_no_acceptance'
            ) NOT NULL
        ");
    }
};
