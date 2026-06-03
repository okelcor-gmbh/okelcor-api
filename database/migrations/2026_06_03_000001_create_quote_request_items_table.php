<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_request_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quote_request_id')
                ->constrained('quote_requests')
                ->cascadeOnDelete();

            // Optional product link (for catalogue-backed items)
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            // Tyre description fields
            $table->string('brand', 100)->nullable();
            $table->string('model', 200)->nullable();
            $table->string('size', 100)->nullable();
            $table->string('season', 50)->nullable();
            $table->string('load_index', 20)->nullable();
            $table->string('speed_index', 10)->nullable();
            $table->string('condition', 30)->nullable(); // new / used

            // Quantity and pricing
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');

            // Admin notes on this line
            $table->string('notes', 500)->nullable();

            // Display ordering
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index('quote_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_request_items');
    }
};
