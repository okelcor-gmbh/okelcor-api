<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-3B — Admin Notification Center & Assignment Work Queue.
 *
 * Extends the generic CRM-3 admin_notifications feed with the richer shape the
 * notification center / work queue needs:
 *   severity, body, action_url, related_type, related_id, dismissed_at, metadata
 *
 * Additive only. The original `message` / `link` columns are kept and are
 * mirrored from `body` / `action_url` by AdminNotificationService so any
 * pre-CRM-3B rows and consumers continue to work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('admin_notifications', 'severity')) {
                $table->string('severity', 16)->default('info')->after('type');
            }
            if (! Schema::hasColumn('admin_notifications', 'body')) {
                $table->text('body')->nullable()->after('title');
            }
            if (! Schema::hasColumn('admin_notifications', 'action_url')) {
                $table->string('action_url')->nullable()->after('link');
            }
            if (! Schema::hasColumn('admin_notifications', 'related_type')) {
                $table->string('related_type', 64)->nullable()->after('action_url');
            }
            if (! Schema::hasColumn('admin_notifications', 'related_id')) {
                $table->unsignedBigInteger('related_id')->nullable()->after('related_type');
            }
            if (! Schema::hasColumn('admin_notifications', 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable()->after('read_at');
            }
            if (! Schema::hasColumn('admin_notifications', 'metadata')) {
                $table->json('metadata')->nullable()->after('dismissed_at');
            }
        });

        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->index(['related_type', 'related_id'], 'admin_notifications_related_index');
            $table->index(['admin_user_id', 'dismissed_at'], 'admin_notifications_user_dismissed_index');
        });
    }

    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->dropIndex('admin_notifications_related_index');
            $table->dropIndex('admin_notifications_user_dismissed_index');

            $table->dropColumn([
                'severity',
                'body',
                'action_url',
                'related_type',
                'related_id',
                'dismissed_at',
                'metadata',
            ]);
        });
    }
};
