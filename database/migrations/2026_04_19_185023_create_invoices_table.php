<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('invoice_number', 50)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('due_at');
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['paid', 'unpaid', 'overdue'])->default('unpaid');
            $table->string('pdf_url', 500)->nullable();
            $table->string('order_ref', 30)->nullable();
            $table->timestamps();

            $table->index('customer_id', 'idx_customer_id');
            $table->index('status', 'invoices_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
