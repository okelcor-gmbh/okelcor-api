<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presence toggle for the mobile app's live-chat routing (see
 * FRONTEND_NOTE_admin-mobile-app-v2-premium.md, Pillar 1) — an admin
 * marks themselves available before a new chat session is routed to them,
 * so a phone doesn't buzz for someone off duty. Defaults false; nobody is
 * "available" until they explicitly opt in from the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->boolean('available_for_chat')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('available_for_chat');
        });
    }
};
