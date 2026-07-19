<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual messages within a live_chat_sessions row — kept lightweight
 * (no timestamps() `updated_at`, messages are never edited) since these
 * are ephemeral by design: rolled up into one customer_communications row
 * on session close (see the sessions table's migration) rather than kept
 * as the permanent record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_chat_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('session_id')->constrained('live_chat_sessions')->cascadeOnDelete();
            $table->enum('sender_type', ['customer', 'admin']);
            $table->unsignedBigInteger('sender_id');
            $table->text('body');

            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_messages');
    }
};
