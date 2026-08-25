<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listings made by hand on ebay.de usually carry no SKU — the title is
 * the only way a human can tell which tyre a row is. Captured from the
 * Trading API alongside price and quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebay_live_listings', function (Blueprint $table) {
            if (! Schema::hasColumn('ebay_live_listings', 'title')) {
                $table->string('title', 255)->nullable()->after('sku');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ebay_live_listings', function (Blueprint $table) {
            if (Schema::hasColumn('ebay_live_listings', 'title')) {
                $table->dropColumn('title');
            }
        });
    }
};
