<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When was this product's Tyre100 cost last refreshed? The pricing tool
 * prices on cost, so a stale cost silently poisons every number after
 * it. Stamped by the dedicated cost import (user's ask, 2026-09-09);
 * null means the cost predates tracking and should be treated as stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'cost_price_updated_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->timestamp('cost_price_updated_at')->nullable()->after('cost_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'cost_price_updated_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('cost_price_updated_at');
            });
        }
    }
};
