<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            // Ownership
            $table->unsignedBigInteger('assigned_to')->nullable()->after('rejection_reason');
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
            $table->timestamp('follow_up_at')->nullable()->after('assigned_at');

            // Lead classification (admin-set, not form input)
            $table->enum('lead_priority', ['low', 'normal', 'high', 'urgent'])
                  ->default('normal')->after('follow_up_at');
            $table->string('lead_source', 60)->nullable()->after('lead_priority');
            $table->enum('lead_customer_type', [
                'private_buyer', 'dealer', 'workshop', 'fleet', 'exporter', 'unknown',
            ])->default('unknown')->after('lead_source');

            // Sales pipeline status (independent of quality review_status)
            $table->enum('qualification_status', [
                'new', 'needs_review', 'qualified',
                'proposal_sent', 'customer_invited', 'converted',
                'rejected', 'spam', 'closed',
            ])->default('new')->after('lead_customer_type');

            $table->text('qualification_reason')->nullable()->after('qualification_status');
            $table->text('internal_notes')->nullable()->after('qualification_reason');

            $table->foreign('assigned_to')->references('id')->on('admin_users')->nullOnDelete();
            $table->index('assigned_to', 'quote_requests_assigned_to_idx');
            $table->index('follow_up_at', 'quote_requests_follow_up_idx');
            $table->index('qualification_status', 'quote_requests_qualification_status_idx');
        });

        // Backfill: derive qualification_status from existing review_status
        DB::statement("
            UPDATE quote_requests
            SET lead_source = 'website_quote',
                qualification_status = CASE review_status
                    WHEN 'qualified'    THEN 'qualified'
                    WHEN 'spam'         THEN 'spam'
                    WHEN 'rejected'     THEN 'rejected'
                    WHEN 'needs_review' THEN 'needs_review'
                    ELSE 'new'
                END
        ");
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropIndex('quote_requests_assigned_to_idx');
            $table->dropIndex('quote_requests_follow_up_idx');
            $table->dropIndex('quote_requests_qualification_status_idx');
            $table->dropColumn([
                'assigned_to', 'assigned_at', 'follow_up_at',
                'lead_priority', 'lead_source', 'lead_customer_type',
                'qualification_status', 'qualification_reason', 'internal_notes',
            ]);
        });
    }
};
