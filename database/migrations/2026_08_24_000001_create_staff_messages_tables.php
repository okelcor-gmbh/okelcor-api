<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal staff-to-staff messaging — the team's own inbox, alongside the
 * customer one.
 *
 * A separate pair of tables rather than more rows in customer_communications:
 * that table is FK'd to customers/quote requests and every read path filters
 * by customer context, so internal mail would be invisible noise there. The
 * column vocabulary (subject/body/attachments/read_at) deliberately matches
 * customer_communications so the frontend can render both inboxes with the
 * same components.
 *
 * Delivery is in-app only (inbox + notification bell + push) — no SMTP hop,
 * so nothing here can loop back through the inbound e-mail webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_messages')) {
            Schema::create('staff_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sender_admin_user_id')->index();
                $table->string('subject', 300)->nullable();
                $table->longText('body')->nullable();
                $table->json('attachments')->nullable();
                // Threading: in_reply_to_id points at the direct parent,
                // thread_root_id at the first message of the thread (null on
                // a root itself) so a whole thread is one indexed lookup.
                $table->unsignedBigInteger('in_reply_to_id')->nullable();
                $table->unsignedBigInteger('thread_root_id')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('staff_message_recipients')) {
            Schema::create('staff_message_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('staff_message_id');
                $table->unsignedBigInteger('recipient_admin_user_id');
                // Plain string, not an ENUM — deliberately, so a future value
                // ('bcc'?) never needs a MySQL ALTER to become storable.
                $table->string('kind', 10)->default('to');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->unique(['staff_message_id', 'recipient_admin_user_id'], 'staff_msg_recipient_unique');
                // The inbox query: my rows, unread first.
                $table->index(['recipient_admin_user_id', 'read_at'], 'staff_msg_recipient_inbox_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_message_recipients');
        Schema::dropIfExists('staff_messages');
    }
};
