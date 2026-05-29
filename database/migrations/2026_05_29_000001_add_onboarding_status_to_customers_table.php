<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('onboarding_status', [
                'pending_review',
                'approved',
                'invited',
                'active',
                'rejected',
                'blocked',
            ])->default('active')->after('status');
        });

        // Backfill: customers with security-banned or suspended status → blocked
        DB::table('customers')
            ->whereIn('status', ['banned', 'suspended'])
            ->update(['onboarding_status' => 'blocked']);

        // All remaining existing customers are already active
        // (the column DEFAULT 'active' handles inserts; existing rows also default to 'active'
        //  via the column default applied by MySQL on ALTER TABLE)
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('onboarding_status');
        });
    }
};
