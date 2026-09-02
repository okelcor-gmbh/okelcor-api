<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote a liquidity line (Session 111).
 *
 * `finance_liquidity_entries` shipped in Session 99 and gained its weekly
 * columns in #59, and at no point did it record a person. That is why finance
 * appears to have done nothing on the one file they work in every week: there
 * was never anything to attribute.
 *
 * **The existing 66 rows cannot be backfilled and are deliberately left null.**
 * Most of them arrived through `liquidity:import --fix --replace`, which is a
 * command, not a person; guessing that whoever ran the import "did" all 64
 * lines would credit one person with a spreadsheet somebody else built. Rule 2
 * of the ledger — no person, no row — applies to history as much as to new
 * work, and an honestly empty past is better than an invented one.
 *
 * Additive, nullable, guarded, no backfill. Nothing existing is read or
 * altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_liquidity_entries')) {
            return;
        }

        Schema::table('finance_liquidity_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('finance_liquidity_entries', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('comment');
                $table->index('created_by');
            }

            if (! Schema::hasColumn('finance_liquidity_entries', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_liquidity_entries')) {
            return;
        }

        Schema::table('finance_liquidity_entries', function (Blueprint $table) {
            if (Schema::hasColumn('finance_liquidity_entries', 'created_by')) {
                $table->dropIndex(['created_by']);
                $table->dropColumn('created_by');
            }

            if (Schema::hasColumn('finance_liquidity_entries', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
        });
    }
};
