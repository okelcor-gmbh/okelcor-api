<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ref_number', 30)->unique();
            $table->string('full_name', 200);
            $table->string('company_name', 200)->nullable();
            $table->string('email', 255);
            $table->string('phone', 50)->nullable();
            $table->string('country', 100);
            $table->string('business_type', 100)->nullable();
            $table->string('tyre_category', 100);
            $table->string('brand_preference', 200)->nullable();
            $table->string('tyre_size', 100)->nullable();
            $table->string('quantity', 100);
            $table->string('budget_range', 100)->nullable();
            $table->string('delivery_location', 300);
            $table->string('delivery_timeline', 100)->nullable();
            $table->text('notes');
            $table->enum('status', ['new', 'reviewing', 'quoted', 'closed'])->default('new');
            $table->text('admin_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('status', 'quote_requests_status_idx');
            $table->index('email', 'quote_requests_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
    }
};
