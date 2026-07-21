<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Segments marketing_contacts by market (e.g. 'asia', 'croatia') so a bulk
 * campaign can be scoped to exactly one — before this, every contact was in
 * one undifferentiated list, so "send to Asian market only" had no way to
 * actually exclude a newly-imported different market's contacts.
 *
 * Deliberately a plain string, not an enum — the marketing team needs to be
 * able to introduce a new market by importing a CSV or adding a contact,
 * with no backend code change required (see AdminMarketingContactController
 * ::markets(), which auto-discovers the distinct list from data rather than
 * a hardcoded set).
 *
 * Backfills every existing row to 'asia' — confirmed with the business that
 * the current contact list (pre-dating this column) is entirely the Asian
 * market campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_contacts', function (Blueprint $table) {
            $table->string('market', 50)->nullable()->after('country');
        });

        DB::table('marketing_contacts')->whereNull('market')->update(['market' => 'asia']);

        Schema::table('marketing_contacts', function (Blueprint $table) {
            $table->index('market');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_contacts', function (Blueprint $table) {
            $table->dropColumn('market');
        });
    }
};
