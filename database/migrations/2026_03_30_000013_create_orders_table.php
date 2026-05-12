<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 30)->unique();
            $table->string('customer_name', 200);
            $table->string('customer_email', 255);
            $table->string('customer_phone', 50)->nullable();
            $table->string('address', 300);
            $table->string('city', 100);
            $table->string('postal_code', 20);
            $table->string('country', 100);
            $table->string('payment_method', 50);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_cost', 10, 2)->default(0.00);
            $table->decimal('total', 10, 2);
            $table->enum('status', ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid');
            $table->enum('mode', ['live', 'manual'])->default('manual');
            $table->text('admin_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('ref', 'idx_ref');
            $table->index('status', 'orders_status_idx');
            $table->index('customer_email', 'orders_customer_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
