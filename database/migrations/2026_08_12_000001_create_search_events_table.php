<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What customers look for in the catalogue.
 *
 * The admin dashboard could already say what was SOLD. It could not say what
 * was looked for and not found, which is the more useful half: a size searched
 * fifty times and never in stock is a stocking decision, and a term that
 * returns nothing every time is a naming or a search problem. Neither was
 * recoverable, because nothing recorded a search.
 *
 * Deliberately NOT a general event table. Page views, click paths and time on
 * page belong to a frontend analytics product; this records the one thing the
 * API is actually in a position to see — a query against the catalogue and how
 * many products came back.
 *
 * Privacy: no IP address, no user agent, no free-text beyond what was typed
 * into the search box. `visitor_hash` is a salted one-way digest that rotates
 * daily, so a day's traffic can be counted by visitor without anyone being
 * identifiable or followable across days. `customer_id` is only ever set when
 * the search was made by a signed-in customer.
 *
 * One new table; nothing existing is read, altered or backfilled, so this
 * cannot affect a live row. Guarded, so a partially-applied deploy re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('search_events')) {
            return;
        }

        Schema::create('search_events', function (Blueprint $table) {
            $table->id();

            // Normalised for grouping (lowercased, collapsed whitespace); the
            // raw term is kept alongside so a report can show what was actually
            // typed rather than a flattened version of it.
            $table->string('term', 150)->nullable();
            $table->string('raw_term', 190)->nullable();

            // The structured filters, as sent. JSON rather than columns because
            // the catalogue's filter set has grown twice already and a new
            // filter must not need a migration to become visible in reporting.
            $table->json('filters')->nullable();

            // The individual dimensions worth grouping and indexing are lifted
            // out of the JSON, since "which rim sizes do people ask for" is the
            // question this table exists to answer quickly.
            $table->string('brand', 100)->nullable();
            $table->string('category', 20)->nullable();
            $table->string('season', 30)->nullable();
            $table->string('width', 10)->nullable();
            $table->string('height', 10)->nullable();
            $table->string('rim', 10)->nullable();

            $table->unsignedInteger('results_count')->default(0);
            // Stored rather than derived from results_count so the index that
            // answers "what found nothing" is a plain boolean scan.
            $table->boolean('has_results')->default(true);

            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('visitor_hash', 64)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('locale', 5)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['created_at']);
            $table->index(['has_results', 'created_at']);
            $table->index(['term', 'created_at']);
            $table->index(['brand', 'created_at']);
            $table->index(['rim', 'created_at']);
            $table->index(['customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_events');
    }
};
