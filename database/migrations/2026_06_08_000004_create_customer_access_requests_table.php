<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-8 — Customer-initiated access requests.
 *
 * A customer who lacks a permission (checkout / documents / wholesale pricing
 * / higher tier) can request it from the portal. Admin approves or rejects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_access_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->enum('requested_access', [
                'checkout', 'documents', 'wholesale_pricing', 'higher_tier',
            ]);

            $table->enum('status', [
                'pending', 'approved', 'rejected', 'cancelled',
            ])->default('pending');

            $table->text('reason')->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->foreign('reviewed_by')->references('id')->on('admin_users')->nullOnDelete();

            $table->index('customer_id');
            $table->index('status');
            // Explicit short name — the auto-generated name exceeds MySQL's
            // 64-char identifier limit.
            $table->index(['customer_id', 'requested_access', 'status'], 'car_cust_access_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_access_requests');
    }
};
