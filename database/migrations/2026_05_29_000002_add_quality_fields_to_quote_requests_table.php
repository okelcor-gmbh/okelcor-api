<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            // Quality scoring — set after submission, never blocking existing rows
            $table->unsignedTinyInteger('quality_score')->nullable()->after('admin_notes');
            $table->json('quality_flags')->nullable()->after('quality_score');

            // Review workflow — separates quality gate from sales workflow (status column)
            $table->enum('review_status', ['new', 'needs_review', 'qualified', 'rejected', 'spam'])
                  ->default('new')
                  ->after('quality_flags');

            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');

            $table->foreign('reviewed_by')->references('id')->on('admin_users')->nullOnDelete();
            $table->index('review_status', 'quote_requests_review_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex('quote_requests_review_status_idx');
            $table->dropColumn([
                'quality_score',
                'quality_flags',
                'review_status',
                'reviewed_by',
                'reviewed_at',
                'rejection_reason',
            ]);
        });
    }
};
