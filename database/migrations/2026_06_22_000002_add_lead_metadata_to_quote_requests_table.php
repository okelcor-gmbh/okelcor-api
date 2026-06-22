<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landing-page lead attribution.
 *
 * Adds a generic JSON `lead_metadata` bag to quote_requests so marketing /
 * SEO landing forms (e.g. /tyre-wholesaler) can persist conversion attribution
 * (utm_*, gclid, fbclid, referrer, landing_page) plus form-specific extras
 * (primary_tyre_interest, estimated_monthly_volume) without widening the
 * relational schema for every campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_requests', 'lead_metadata')) {
                $table->json('lead_metadata')->nullable()->after('lead_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropColumn('lead_metadata');
        });
    }
};
