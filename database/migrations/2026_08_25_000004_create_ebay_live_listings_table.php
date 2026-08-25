<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of what is ACTUALLY listed on eBay: one row per SKU eBay holds,
 * with the live price, status and quantity as the marketplace shows them.
 * Refreshed by the audit board's "Fetch live from eBay" sync; the pricing
 * audit reconciles this against our products to expose price drift,
 * listings our catalogue does not know, and "listed" products that are
 * not actually live.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ebay_live_listings')) {
            Schema::create('ebay_live_listings', function (Blueprint $table) {
                $table->id();
                $table->string('sku')->unique();
                $table->string('offer_id')->nullable();
                $table->string('listing_id')->nullable();
                $table->string('status', 30)->default('unknown');
                $table->decimal('price', 10, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->integer('quantity')->nullable();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->timestamp('fetched_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_live_listings');
    }
};
