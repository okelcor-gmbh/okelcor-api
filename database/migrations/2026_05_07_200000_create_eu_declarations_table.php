<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eu_declarations', function (Blueprint $table) {
            $table->id();

            // Order is required — declaration is always per order
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            // Denormalised snapshot — captured at creation time, not joined at render time
            $table->string('order_ref', 30);
            $table->string('company_name', 200);
            $table->string('customer_email', 255);
            $table->text('customer_address')->nullable();
            $table->string('vat_number', 30);
            $table->string('country', 100);
            $table->text('goods_description');
            $table->string('quantity_description', 300)->nullable();

            // Customer-completed fields — filled at signing time
            $table->string('member_state_of_entry', 100)->nullable();
            $table->string('place_of_entry', 200)->nullable();
            $table->string('month_year_received', 7)->nullable();       // MM/YYYY
            $table->boolean('self_transported')->default(false);
            $table->string('month_year_transport_ended', 7)->nullable(); // MM/YYYY — only when self_transported

            // Signatory
            $table->string('representative_name', 200)->nullable();
            $table->string('representative_title', 100)->nullable();
            $table->string('signed_name', 200)->nullable();              // CAPITAL LETTERS per form

            // Declaration
            $table->boolean('accepted_terms')->default(false);
            $table->date('issue_date')->nullable();
            $table->timestamp('signed_at')->nullable();

            // Stored files — private disk, never served directly
            $table->string('signature_path', 500)->nullable();
            $table->string('pdf_path', 500)->nullable();

            // Workflow status
            $table->enum('status', ['pending', 'signed', 'acknowledged'])->default('pending');
            $table->timestamp('admin_acknowledged_at')->nullable();
            $table->unsignedBigInteger('admin_acknowledged_by')->nullable();

            // Audit
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index('order_ref');
            $table->index('status');
            $table->index('customer_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eu_declarations');
    }
};
