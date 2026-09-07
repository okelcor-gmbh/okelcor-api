<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pricing tier the margin formula keys on: premium (15%), midrange
 * (20%), budget (30%) — margins configurable in services.pricing. Plain
 * string, not ENUM (the enum lesson), validated in code against
 * TierPricingService::TIERS. Nullable on purpose: an unassigned product
 * is skipped by the repricer, never guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'price_tier')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('price_tier', 20)->nullable()->after('cost_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'price_tier')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('price_tier');
            });
        }
    }
};
