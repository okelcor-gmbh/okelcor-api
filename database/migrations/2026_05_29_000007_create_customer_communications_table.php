<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_communications', function (Blueprint $table) {
            $table->id();

            // Context — at least one should be set, but all are nullable for flexibility
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('quote_request_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable();

            $table->enum('type', ['email', 'call', 'whatsapp', 'note', 'system'])
                  ->default('note');
            $table->enum('direction', ['inbound', 'outbound', 'internal'])
                  ->default('outbound');

            $table->string('subject', 300)->nullable();
            $table->text('body')->nullable();

            $table->enum('status', ['planned', 'sent', 'failed', 'completed', 'skipped'])
                  ->default('completed');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('quote_request_id')->references('id')->on('quote_requests')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('admin_user_id')->references('id')->on('admin_users')->nullOnDelete();

            $table->index('customer_id', 'cc_customer_idx');
            $table->index('quote_request_id', 'cc_quote_idx');
            $table->index(['type', 'status'], 'cc_type_status_idx');
            $table->index('scheduled_at', 'cc_scheduled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_communications');
    }
};
