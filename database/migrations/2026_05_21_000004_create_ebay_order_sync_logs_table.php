<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_order_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('ebay_order_id', 50)->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('action', 20); // imported | updated | skipped | failed
            $table->string('status', 30)->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload_summary')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_order_sync_logs');
    }
};
