<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-8 — Buyer Approval & Customer Lifecycle Management.
 *
 * Adds buyer-lifecycle fields that sit ABOVE the CRM-4 access fields
 * (access_level / approved_for_* ). Nothing here replaces CRM-4 — these
 * columns make the existing access flags easier to manage as a managed
 * buyer lifecycle (tier, verification, health, risk, approval audit).
 *
 * Safety: purely additive. Existing active customers are backfilled to
 * verified / low-risk so they never appear in the pending-approval or
 * high-risk queues and never lose access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('buyer_tier', [
                'bronze', 'silver', 'gold', 'platinum', 'vip', 'none',
            ])->default('none')->after('approved_for_documents');

            $table->enum('verification_status', [
                'not_started', 'pending_review', 'verified', 'rejected',
            ])->default('not_started')->after('buyer_tier');

            $table->unsignedTinyInteger('health_score')->nullable()->after('verification_status');

            $table->enum('risk_level', [
                'low', 'medium', 'high', 'critical', 'unknown',
            ])->default('unknown')->after('health_score');

            $table->unsignedBigInteger('approved_by')->nullable()->after('risk_level');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_notes')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('approval_notes');

            $table->index('buyer_tier');
            $table->index('verification_status');
            $table->index('risk_level');
        });

        // SQLite cannot add a foreign key via ALTER TABLE — only constrain on
        // engines that support it (MySQL in dev/prod). The index above is enough
        // for lookups; referential integrity is enforced where the engine allows.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreign('approved_by')->references('id')->on('admin_users')->nullOnDelete();
            });
        }

        // Backfill: existing active customers are treated as already-verified,
        // low-risk approved buyers so CRM-8 never demotes a live account.
        DB::table('customers')
            ->where('is_active', true)
            ->update([
                'verification_status' => 'verified',
                'risk_level'          => 'low',
            ]);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['approved_by']);
            });
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['buyer_tier']);
            $table->dropIndex(['verification_status']);
            $table->dropIndex(['risk_level']);
            $table->dropColumn([
                'buyer_tier',
                'verification_status',
                'health_score',
                'risk_level',
                'approved_by',
                'approved_at',
                'approval_notes',
                'rejection_reason',
            ]);
        });
    }
};
