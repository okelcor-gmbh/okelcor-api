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
            $table->enum('customer_segment', [
                'private_buyer', 'dealer', 'workshop', 'fleet',
                'exporter', 'distributor', 'partner', 'unknown',
            ])->default('unknown')->after('admin_notes');

            $table->enum('access_level', [
                'inquiry_only', 'quote_only', 'approved_buyer',
                'wholesale_buyer', 'restricted', 'blocked',
            ])->default('inquiry_only')->after('customer_segment');

            $table->enum('market_region', [
                'eu', 'africa', 'middle_east', 'global', 'unknown',
            ])->default('unknown')->after('access_level');

            $table->boolean('approved_for_checkout')->default(false)->after('market_region');
            $table->boolean('approved_for_quotes')->default(true)->after('approved_for_checkout');
            $table->boolean('approved_for_wholesale_pricing')->default(false)->after('approved_for_quotes');
            $table->boolean('approved_for_documents')->default(false)->after('approved_for_wholesale_pricing');
        });

        // Backfill: existing fully-active customers get full approved_buyer access
        DB::table('customers')
            ->where('onboarding_status', 'active')
            ->where('is_active', true)
            ->update([
                'access_level'         => 'approved_buyer',
                'approved_for_checkout' => true,
                'approved_for_quotes'   => true,
                'approved_for_documents' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'customer_segment',
                'access_level',
                'market_region',
                'approved_for_checkout',
                'approved_for_quotes',
                'approved_for_wholesale_pricing',
                'approved_for_documents',
            ]);
        });
    }
};
