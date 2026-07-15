<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp reuses the communication log built for the Outlook-style e-mail
 * feature (same customer_id/quote_request_id linkage, same `channel`/
 * `attachments`/`message_id`/`in_reply_to`/`staff_read_at`/`customer_read_at`
 * columns already added there — `type` already had 'whatsapp' as a valid
 * enum value since CRM-6, unused until now). Only WhatsApp-specific fields
 * are new here: a phone number to key on (WhatsApp has no e-mail concept),
 * Meta's own message id (kept separate from the generic `message_id` column
 * used for e-mail threading, since WhatsApp's id format/semantics differ and
 * status-webhook matching needs to be unambiguous), and delivery status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_communications', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_communications', 'phone_number')) {
                $table->string('phone_number', 30)->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('customer_communications', 'whatsapp_message_id')) {
                $table->string('whatsapp_message_id', 100)->nullable()->unique()->after('in_reply_to');
            }
            if (! Schema::hasColumn('customer_communications', 'whatsapp_status')) {
                // Plain string, not an ENUM — Meta's status set (sent/delivered/
                // read/failed) has grown before and we don't want a repeat of
                // the admin_users.role ENUM gap over a webhook-driven column.
                $table->string('whatsapp_status', 20)->nullable()->after('whatsapp_message_id');
            }
            if (! Schema::hasColumn('customer_communications', 'whatsapp_template_name')) {
                $table->string('whatsapp_template_name', 100)->nullable()->after('whatsapp_status');
            }

            $table->index('phone_number', 'cc_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_communications', function (Blueprint $table) {
            $table->dropIndex('cc_phone_idx');
            $table->dropColumn(['phone_number', 'whatsapp_message_id', 'whatsapp_status', 'whatsapp_template_name']);
        });
    }
};
