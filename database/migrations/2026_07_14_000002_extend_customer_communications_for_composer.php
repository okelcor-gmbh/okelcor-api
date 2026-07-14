<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outlook-style compose/reply — extends the existing CRM-6 communication log
 * (rather than a new table) with what a real send needs: CC, attachments,
 * threading headers, per-side read receipts, and a channel discriminator
 * (real e-mail vs. a reply made through the customer's own portal account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_communications', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_communications', 'cc')) {
                $table->json('cc')->nullable()->after('body');
            }
            if (! Schema::hasColumn('customer_communications', 'attachments')) {
                $table->json('attachments')->nullable()->after('cc');
            }
            if (! Schema::hasColumn('customer_communications', 'channel')) {
                // Plain string, not an ENUM — deliberately, so a future value
                // never needs a MySQL ALTER to become storable.
                $table->string('channel', 20)->nullable()->after('direction');
            }
            if (! Schema::hasColumn('customer_communications', 'message_id')) {
                $table->string('message_id', 255)->nullable()->unique()->after('attachments');
            }
            if (! Schema::hasColumn('customer_communications', 'in_reply_to')) {
                $table->string('in_reply_to', 255)->nullable()->after('message_id');
            }
            if (! Schema::hasColumn('customer_communications', 'staff_read_at')) {
                $table->timestamp('staff_read_at')->nullable()->after('in_reply_to');
            }
            if (! Schema::hasColumn('customer_communications', 'customer_read_at')) {
                $table->timestamp('customer_read_at')->nullable()->after('staff_read_at');
            }
        });

        // Widen body from TEXT (64KB) to LONGTEXT — a pasted-from-Outlook
        // rich HTML message body can exceed a capped TEXT column well before
        // anything unusual has happened. Raw SQL because doctrine/dbal
        // (required for Blueprint::change()) isn't installed in this project.
        DB::statement('ALTER TABLE customer_communications MODIFY COLUMN body LONGTEXT NULL');
    }

    public function down(): void
    {
        Schema::table('customer_communications', function (Blueprint $table) {
            $table->dropColumn(['cc', 'attachments', 'channel', 'message_id', 'in_reply_to', 'staff_read_at', 'customer_read_at']);
        });

        DB::statement('ALTER TABLE customer_communications MODIFY COLUMN body TEXT NULL');
    }
};
