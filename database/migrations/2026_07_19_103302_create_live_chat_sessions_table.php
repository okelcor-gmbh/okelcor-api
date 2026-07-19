<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per live-chat conversation (see
 * FRONTEND_NOTE_admin-mobile-app-v2-premium.md, Pillar 1). Individual
 * messages live in live_chat_messages while the session is open, for fast
 * real-time delivery; on close, the full transcript is rolled up into a
 * single customer_communications row (communication_id below) so it shows
 * up in that customer's unified history exactly like an e-mail or
 * WhatsApp thread — no permanent parallel data model for anything other
 * than the live/in-progress state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_chat_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('communication_id')->nullable()->constrained('customer_communications')->nullOnDelete();

            $table->enum('status', ['pending', 'active', 'closed'])->default('pending');
            $table->string('closed_reason', 100)->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            $table->index('customer_id');
            $table->index('admin_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_sessions');
    }
};
