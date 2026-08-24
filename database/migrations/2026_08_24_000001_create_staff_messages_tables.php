<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-to-staff messaging (Session 97).
 *
 * Every messaging path in this app so far runs admin → customer:
 * `customer_communications` is keyed to a customer or a quote request and
 * cannot represent "Ada sent this to Ben". These two tables are the missing
 * half, and they are deliberately NOT bolted onto that table — a nullable
 * customer_id on a staff message would make every existing customer-scoped
 * query (the inbox feed, the per-customer thread, the portal) responsible
 * for remembering to exclude internal mail. One forgotten `whereNotNull`
 * there leaks staff correspondence into a customer's own portal.
 *
 * Both tables are new. Nothing existing is read, altered or backfilled, so
 * this cannot affect a live row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_messages')) {
            Schema::create('staff_messages', function (Blueprint $table) {
                $table->id();

                // Groups a message with its replies. Set to the root message's
                // own uuid on compose and copied verbatim by every reply, so a
                // thread is one indexed lookup rather than a recursive walk.
                $table->uuid('thread_id')->index();

                // Nullable + nullOnDelete: deleting an admin account must not
                // delete the correspondence. Who sent it is also denormalised
                // onto sender_label below for exactly that case.
                $table->foreignId('sender_admin_id')->nullable()
                    ->constrained('admin_users')->nullOnDelete();
                $table->string('sender_label', 191)->nullable();

                $table->string('subject', 300);
                $table->longText('body');                       // sanitized HTML
                $table->json('attachments')->nullable();

                $table->foreignId('in_reply_to_id')->nullable()
                    ->constrained('staff_messages')->nullOnDelete();

                // Forward provenance. Kept as plain unsigned ints with no FK:
                // a forwarded copy must survive the original communication
                // being deleted, and it carries its own body/attachments, so
                // there is nothing to cascade.
                $table->unsignedBigInteger('forwarded_from_communication_id')->nullable()->index();
                $table->unsignedBigInteger('forwarded_from_customer_id')->nullable();
                $table->unsignedBigInteger('forwarded_from_quote_request_id')->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('staff_message_recipients')) {
            Schema::create('staff_message_recipients', function (Blueprint $table) {
                $table->id();

                $table->foreignId('staff_message_id')
                    ->constrained('staff_messages')->cascadeOnDelete();
                $table->foreignId('admin_user_id')
                    ->constrained('admin_users')->cascadeOnDelete();

                // 'to' or 'cc'. A plain string, not an ENUM — see the
                // admin_users.role / order_logs.action ENUM trap in
                // PROGRESS.md, walked into twice already.
                $table->string('kind', 8)->default('to');

                $table->timestamp('read_at')->nullable();

                // Per-recipient outcome of the real e-mail copy: sent, failed
                // or skipped. Per-recipient because one bad address must not
                // report the whole send as failed.
                $table->string('email_status', 16)->nullable();
                $table->text('email_error')->nullable();

                $table->timestamps();

                // One row per person per message — makes "add me again as CC"
                // a no-op rather than a duplicate inbox entry.
                $table->unique(['staff_message_id', 'admin_user_id'], 'staff_msg_recipient_unique');

                // The inbox query: my rows, unread first, newest first.
                $table->index(['admin_user_id', 'read_at'], 'staff_msg_recipient_inbox');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_message_recipients');
        Schema::dropIfExists('staff_messages');
    }
};
