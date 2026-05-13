<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend the order_logs.action enum to include document-lifecycle values
     * added in sessions 7–11 (document_generated / uploaded / deleted) plus
     * the new document_sent value from Phase 2C-5.
     * Safe to run on a DB that already has the document_ values — MySQL enum
     * modifications are idempotent when adding values.
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
                'document_sent'
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
                'document_deleted'
            ) NOT NULL
        ");
    }
};
