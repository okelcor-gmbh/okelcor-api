<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            // Core proposal lifecycle
            $table->enum('proposal_status', [
                'none', 'draft', 'ready', 'sent', 'accepted', 'rejected', 'expired', 'converted',
            ])->default('none')->after('internal_notes');

            $table->string('proposal_number', 50)->nullable()->after('proposal_status');

            // Items snapshot stored at draft time (JSON array of line items)
            $table->json('proposal_items')->nullable()->after('proposal_number');
            $table->decimal('proposal_total', 10, 2)->nullable()->after('proposal_items');
            $table->string('proposal_currency', 3)->default('EUR')->after('proposal_total');

            // Token-based public acceptance
            $table->string('proposal_acceptance_token', 128)->nullable()->unique()->after('proposal_currency');

            // Lifecycle timestamps
            $table->timestamp('proposal_sent_at')->nullable()->after('proposal_acceptance_token');
            $table->timestamp('proposal_accepted_at')->nullable()->after('proposal_sent_at');
            $table->timestamp('proposal_rejected_at')->nullable()->after('proposal_accepted_at');
            $table->timestamp('proposal_expires_at')->nullable()->after('proposal_rejected_at');

            // Void tracking
            $table->timestamp('proposal_voided_at')->nullable()->after('proposal_expires_at');
            $table->foreignId('proposal_voided_by')
                ->nullable()
                ->constrained('admin_users')
                ->nullOnDelete()
                ->after('proposal_voided_at');
            $table->text('proposal_void_reason')->nullable()->after('proposal_voided_by');

            // Rejection reason (from customer)
            $table->text('proposal_rejection_reason')->nullable()->after('proposal_void_reason');

            // PDF path (private disk — proposals/QT-YYYY-XXXX.pdf)
            $table->string('proposal_pdf_path', 500)->nullable()->after('proposal_rejection_reason');

            // Acceptance metadata (IP / UA for audit trail)
            $table->string('proposal_accepted_ip', 45)->nullable()->after('proposal_pdf_path');
            $table->text('proposal_accepted_user_agent')->nullable()->after('proposal_accepted_ip');
            $table->string('proposal_acceptance_note', 500)->nullable()->after('proposal_accepted_user_agent');

            // Index for token lookup
            $table->index('proposal_status');
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropForeign(['proposal_voided_by']);

            $table->dropColumn([
                'proposal_status',
                'proposal_number',
                'proposal_items',
                'proposal_total',
                'proposal_currency',
                'proposal_acceptance_token',
                'proposal_sent_at',
                'proposal_accepted_at',
                'proposal_rejected_at',
                'proposal_expires_at',
                'proposal_voided_at',
                'proposal_voided_by',
                'proposal_void_reason',
                'proposal_rejection_reason',
                'proposal_pdf_path',
                'proposal_accepted_ip',
                'proposal_accepted_user_agent',
                'proposal_acceptance_note',
            ]);
        });
    }
};
