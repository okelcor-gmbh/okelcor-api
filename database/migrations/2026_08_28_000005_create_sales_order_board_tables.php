<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Order Management board (Session 101, from finance's OT 3.html
 * mockup).
 *
 * A hand-entered ledger of orders, each itemizing CUSTOMER lines (revenue +
 * tyre quantity) against SUPPLIER lines (costs + their invoice documents).
 * Gross profit, margin and the verification status are never stored — GP is
 * customer minus supplier, and an order is "verified" exactly when it has
 * supplier lines and every one carries its document. Storing any of that
 * would let the figure disagree with the lines it summarises.
 *
 * Deliberately decoupled from the operational `orders` table, like the
 * finance snapshot: finance types what their books say — including orders
 * this system has never seen — and the free-text order number is the join a
 * human makes, not a foreign key.
 *
 * Two NEW tables; nothing existing is read, altered or backfilled.
 * Deploy-order safe: readers go through SalesOrderEntry::available().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_order_entries')) {
            Schema::create('sales_order_entries', function (Blueprint $table) {
                $table->id();

                // Free text, but unique — the same real order entered twice
                // would double the KPIs, and a duplicate is a friendly 422.
                $table->string('order_no', 50)->unique();

                $table->string('customer_name', 150);

                // B2B | B2C (SalesOrderEntry::SEGMENTS) — the split the two
                // margin KPIs report on.
                $table->string('segment', 5)->default('B2B');

                // 'YYYY-MM' — the month the order belongs to.
                $table->string('period', 7)->index();

                // Tyres | FET (SalesOrderEntry::CATEGORIES).
                $table->string('category', 20)->default('Tyres');

                $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_order_lines')) {
            Schema::create('sales_order_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entry_id')->constrained('sales_order_entries')->cascadeOnDelete();

                // customer | supplier (SalesOrderLine::PARTY_TYPES). Customer
                // lines are the revenue side and carry the tyre quantity;
                // supplier lines are the cost side and owe a document.
                $table->string('party_type', 10);
                $table->string('party_name', 150);

                $table->unsignedInteger('tyre_qty')->default(0);
                $table->decimal('amount', 12, 2)->default(0);

                // The invoice / proof document, private disk — same columns
                // as every other finance upload.
                $table->string('file_path', 500)->nullable();
                $table->string('original_filename', 255)->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamp('uploaded_at')->nullable();

                $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_order_entries');
    }
};
