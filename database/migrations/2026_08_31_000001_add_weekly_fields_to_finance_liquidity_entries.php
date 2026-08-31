<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The liquidity working becomes finance's weekly file (Session 105).
 *
 * Finance sent "Liquidity File V1.xlsx": a Summary grid of categories ×
 * ISO weeks over a Details ledger whose rows carry Supplier, Description,
 * Week, Currency, Amount and a Comment. The existing two-period model
 * (open_current / next_month) cannot express any of that, so entries gain:
 *
 *   - week_key  ('2026-W35') — the bucket the summary grid pivots on.
 *   - supplier  — who the money goes to / comes from.
 *   - currency  — displayed only; amounts in the file are already EUR and
 *                 nothing converts (the operations-board rule).
 *   - comment   — "To Pay on 30-Sep-2026" and the like.
 *
 * All additive, guarded and nullable — `period` stays, so the D13 restore
 * path and any old-format rows are untouched. New rows are week-keyed;
 * period-keyed rows simply do not appear in the weekly grid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_liquidity_entries')) {
            return;
        }

        Schema::table('finance_liquidity_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('finance_liquidity_entries', 'week_key')) {
                $table->string('week_key', 10)->nullable()->after('period')->index();
            }
            if (! Schema::hasColumn('finance_liquidity_entries', 'supplier')) {
                $table->string('supplier', 150)->nullable()->after('week_key');
            }
            if (! Schema::hasColumn('finance_liquidity_entries', 'currency')) {
                $table->string('currency', 3)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('finance_liquidity_entries', 'comment')) {
                $table->string('comment', 255)->nullable()->after('currency');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_liquidity_entries')) {
            return;
        }

        Schema::table('finance_liquidity_entries', function (Blueprint $table) {
            foreach (['week_key', 'supplier', 'currency', 'comment'] as $column) {
                if (Schema::hasColumn('finance_liquidity_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
