<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly liquidity ladder (Session 99, the finance discussion note).
 *
 * Finance plans cash by ISO week — "we are in week 35" — looking at the
 * current week and the three ahead of it. One row per week, holding the bank
 * balance and the expected movements finance types in.
 *
 * The rolling window is a READ-time concern, not a schema one: the endpoint
 * returns the current week plus three, so a week that has ended falls out of
 * the view by the calendar moving, and its row survives untouched as history.
 * Nothing here marks a week "closed" — a closed week is simply one whose
 * end date has passed, and a flag column would be a second copy of the
 * calendar that can disagree with it.
 *
 * `week_key` is the same `o-\WW` format the operations report buckets by
 * ('2026-W35'), so the two features can never disagree about which week a
 * date falls in.
 *
 * One new table. Nothing existing is read, altered or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('liquidity_weeks')) {
            return;
        }

        Schema::create('liquidity_weeks', function (Blueprint $table) {
            $table->id();

            // '2026-W35' — ISO year + ISO week, zero-padded.
            $table->string('week_key', 8)->unique();

            // Denormalised from the key for ordering — string ordering happens
            // to work within a year but '2026-W09' vs '2026-W10' style bugs are
            // exactly what a numeric sort key exists to avoid.
            $table->unsignedSmallInteger('iso_year');
            $table->unsignedTinyInteger('iso_week');

            $table->date('starts_on');
            $table->date('ends_on');

            // What finance actually maintains. All nullable — a week can be
            // created with any one of them; null means "not entered", which is
            // different from zero.
            $table->decimal('bank_balance', 14, 2)->nullable();
            $table->decimal('expected_in', 14, 2)->nullable();
            $table->decimal('expected_out', 14, 2)->nullable();
            $table->string('notes', 1000)->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['iso_year', 'iso_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidity_weeks');
    }
};
