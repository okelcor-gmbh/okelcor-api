<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('order_ref', 30)->nullable();
            $table->date('event_date');
            $table->string('location', 200)->nullable();
            $table->string('status_label', 100);
            $table->string('description', 500)->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipment_events');
    }
};
