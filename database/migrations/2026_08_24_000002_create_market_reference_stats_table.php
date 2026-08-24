<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External market data, per country (Session 98).
 *
 * The market report can only rank countries someone has already visited from.
 * A country with no traffic scores zero, which is indistinguishable on the
 * page from a country with no demand — and that is precisely the market you
 * might most want to enter. This table is where outside evidence lands so it
 * can sit beside the observed numbers instead of the report pretending it
 * knows something it does not.
 *
 * Deliberately a thin key/value store rather than columns per metric: nobody
 * has produced this data yet, so the shape it arrives in is unknown, and a
 * schema guess would need a migration the first time it is wrong. `metric` is
 * a free string, not an ENUM — see the order_logs.action ENUM trap this
 * project has walked into three times.
 *
 * One new table. Nothing existing is read, altered or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_reference_stats')) {
            return;
        }

        Schema::create('market_reference_stats', function (Blueprint $table) {
            $table->id();

            // ISO-2, already normalised by CountryNormaliser on import. Rows
            // whose country could not be resolved are rejected at import
            // rather than stored under a code nobody can join to.
            $table->string('country_code', 2)->index();

            // e.g. 'tyre_import_volume_usd', 'vehicle_fleet_size',
            // 'passenger_car_registrations'. Free text on purpose.
            $table->string('metric', 80);

            $table->decimal('value', 20, 4);
            $table->string('unit', 30)->nullable();          // 'USD', 'units', '%'

            // Which year/quarter the figure describes — NOT when it was
            // imported. A 2024 trade figure loaded today is still a 2024
            // figure, and a report that showed the import date would age
            // invisibly.
            $table->string('period', 20)->nullable();

            // Where it came from, so a number on a business decision can be
            // traced back to something citable.
            $table->string('source', 190)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Re-importing a corrected figure replaces it rather than
            // doubling it — the import uses updateOrCreate on this key.
            $table->unique(['country_code', 'metric', 'period'], 'market_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_reference_stats');
    }
};
