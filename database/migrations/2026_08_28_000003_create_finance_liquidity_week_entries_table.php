<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The liquidity board, rebucketed from months into weeks.
 *
 * `finance_liquidity_entries` holds the original two buckets (open current
 * month / next month). Finance now works a rolling four-week window instead —
 * "we are in week 35, show me 35 to 38, and when a week closes it drops off" —
 * so each entry is pinned to the Monday of its week rather than to a named
 * period. The window itself is never stored: the API computes it from today's
 * date, which is what makes closed weeks disappear without anything deleting
 * them. History stays in the table.
 *
 * The `line` vocabulary is FinanceLiquidityEntry::LINES, unchanged, so the
 * bank balance is just the `bank_balance` line of a given week.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_liquidity_week_entries')) {
            return;
        }

        Schema::create('finance_liquidity_week_entries', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');          // always the Monday, normalized on write
            $table->string('line', 40);
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->foreignId('recorded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['week_start', 'line'], 'liquidity_week_entries_week_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_liquidity_week_entries');
    }
};
