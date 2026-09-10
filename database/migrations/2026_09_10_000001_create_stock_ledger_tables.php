<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The finance stock ledger (their own draft, stock.html, 2026-09-10):
 * physical used-tyre stock by brand + size + condition grade, booked IN
 * by multi-line supplier invoices and OUT by multi-line customer sale
 * invoices, with every invoice kept as the audit trail. Deliberately
 * separate from `products` — this tracks warehouse pieces with tread
 * depth and grades, not the web catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_items')) {
            Schema::create('stock_items', function (Blueprint $table) {
                $table->id();
                $table->string('brand', 100);
                $table->string('size', 50);
                $table->string('tread', 20)->nullable();
                $table->string('grade', 10)->default('A');
                $table->integer('qty')->default(0);
                $table->decimal('cost', 10, 2)->default(0);
                $table->timestamps();
                $table->index(['brand', 'size', 'grade']);
            });
        }

        if (! Schema::hasTable('stock_transactions')) {
            Schema::create('stock_transactions', function (Blueprint $table) {
                $table->id();
                $table->string('type', 10); // supplier | customer
                $table->string('party_name', 190);
                $table->string('reference', 100)->nullable();
                $table->integer('total_qty')->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->json('lines');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index('type');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
        Schema::dropIfExists('stock_items');
    }
};
