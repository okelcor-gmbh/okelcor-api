<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Portal Notifications ("Email = Inbox").
 *
 * The customer-facing twin of the admin CRM-3B notification feed. Every
 * transactional email a customer receives also writes a row here, so the
 * portal bell / inbox mirror the customer's email. See the frontend contract
 * "Backend Contract — Customer Portal Notifications".
 *
 * Additive + guarded so it is safe to re-run and safe to deploy ahead of the
 * trigger wiring (the FE degrades gracefully until rows exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_notifications')) {
            Schema::create('customer_notifications', function (Blueprint $table) {
                $table->id();

                $table->foreignId('customer_id')
                    ->constrained('customers')
                    ->cascadeOnDelete();

                $table->string('type', 48);
                $table->string('title', 255);
                $table->text('body')->nullable();
                $table->string('severity', 16)->default('info'); // info | success | warning | urgent
                $table->string('action_url', 512)->nullable();   // relative in-app path only
                $table->string('related_type', 48)->nullable();  // order | quote_request | proposal | trade_document | access_request | verification | account
                $table->string('related_id', 64)->nullable();    // string: refs like AB-1042 / INV-2031
                $table->timestamp('read_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamp('email_sent_at')->nullable();   // set when the same event was also emailed
                $table->json('metadata')->nullable();

                $table->timestamps();

                // Cheap polled unread count + inbox ordering + dedupe lookups.
                $table->index(['customer_id', 'read_at', 'dismissed_at'], 'cust_notif_unread_index');
                $table->index(['customer_id', 'created_at'], 'cust_notif_created_index');
                $table->index(['customer_id', 'type', 'related_type', 'related_id'], 'cust_notif_dedupe_index');
            });
        }

        // Notification delivery preferences (email / in-app toggles).
        // Stored as JSON on customers — shape = CustomerNotificationPreferences.
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'notification_preferences')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->json('notification_preferences')->nullable()->after('preferred_language');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'notification_preferences')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('notification_preferences');
            });
        }
    }
};
