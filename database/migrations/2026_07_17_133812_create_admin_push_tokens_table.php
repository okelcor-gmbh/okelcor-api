<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expo push tokens for the admin/ops companion mobile app (see
 * FRONTEND_NOTE_admin-mobile-app.md). One row per physical device — `token`
 * is unique, not `admin_id`, because a device belongs to whoever most
 * recently logged into the app on it; registering re-points an existing
 * row at the new admin rather than creating a duplicate (see
 * AdminPushTokenController::store, an upsert keyed on token).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_push_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_id')
                ->constrained('admin_users')
                ->cascadeOnDelete();

            $table->string('token', 255)->unique();
            $table->string('platform', 20);
            $table->timestamp('last_seen_at');

            $table->timestamps();

            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_push_tokens');
    }
};
