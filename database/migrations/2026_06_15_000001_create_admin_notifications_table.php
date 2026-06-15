<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-3 — Admin notifications (lead assignment, etc.)
 *
 * Generic per-admin-user notification feed. `type` and `link` are
 * deliberately generic so this table can be reused for future event
 * types (follow-up due, proposal accepted, etc.) without schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_user_id')
                ->constrained('admin_users')
                ->cascadeOnDelete();

            $table->string('type', 64);
            $table->string('title', 255);
            $table->text('message')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['admin_user_id', 'read_at']);
            $table->index(['admin_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
