<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costs on an order that arrive without an invoice.
 *
 * eBay's final-value fee, Stripe's processing cut, a bank transfer charge —
 * they hit the margin exactly like a supplier invoice does, but there is no
 * document to file in the register, only a line on a statement. One row per
 * charge, keyed to the order both by FK and by ref string, matching how
 * everything else in the finance corner joins (`finance_invoices.order_ref`,
 * `order_signoffs.order_ref`).
 *
 * Amounts are entered as positive magnitudes ("this cost €12.40"); a negative
 * amount is allowed for a correction or a refunded fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_costs')) {
            return;
        }

        Schema::create('order_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('order_ref', 30);
            $table->string('type', 30);          // ebay_fee | stripe_fee | bank_cost | shipping | customs | other
            $table->string('label', 150)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('EUR');
            $table->foreignId('recorded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_ref', 'order_costs_order_ref_idx');
            $table->index(['order_id', 'type'], 'order_costs_order_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_costs');
    }
};
