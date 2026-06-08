<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-8 — Customer lifecycle timeline.
 *
 * Append-only audit trail of buyer-lifecycle events
 * (customer_created, lead_converted, proposal_accepted, customer_approved,
 *  tier_changed, verification_updated, risk_level_changed, customer_blocked …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_timeline_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('admin_user_id')->nullable();

            $table->string('event_type', 64);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->foreign('admin_user_id')->references('id')->on('admin_users')->nullOnDelete();

            $table->index('customer_id');
            $table->index(['customer_id', 'event_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_timeline_events');
    }
};
