<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The finance team's "Financial Snapshot" board, moved off a single
 * browser's localStorage (D13.html) into the database so it is shared,
 * backed up, and editable by more than one person.
 *
 * Two tables, mirroring the two halves of the original:
 *  - finance_snapshot_items: the six-category pipeline (open proposals,
 *    open orders, outstanding invoices, shipments in transit, delivery
 *    confirmations, pending receipts), one row per tracked record,
 *    attributed to the staff member handling it.
 *  - finance_liquidity_entries: the "Finance Liquidity Working" lines
 *    (bank balance, cost of sales, rent, …, revenue payment), one row per
 *    breakdown entry, bucketed into the open current month or the next
 *    month forecast. The derived rows (cash position, injection request,
 *    forecast) are computed, never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_snapshot_items')) {
            Schema::create('finance_snapshot_items', function (Blueprint $table) {
                $table->id();
                $table->string('category', 40);
                $table->string('person', 100);
                $table->string('ref', 50);
                $table->date('date')->nullable();
                $table->string('client', 255)->nullable();
                $table->string('status', 30)->default('Pending');
                $table->string('comment', 500)->nullable();
                $table->decimal('amount', 12, 2)->default(0);
                $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();
                $table->index('category');
                $table->index('person');
            });
        }

        if (! Schema::hasTable('finance_liquidity_entries')) {
            Schema::create('finance_liquidity_entries', function (Blueprint $table) {
                $table->id();
                $table->string('line', 40);
                $table->string('period', 20);   // open_current | next_month
                $table->string('description', 255);
                $table->string('reference', 100)->nullable();
                $table->decimal('amount', 12, 2)->default(0);
                $table->timestamps();
                $table->index('line');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_liquidity_entries');
        Schema::dropIfExists('finance_snapshot_items');
    }
};
