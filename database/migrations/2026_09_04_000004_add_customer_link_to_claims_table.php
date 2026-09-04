<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claims reach the customer portal (Session 120).
 *
 * Session 119 gave staff the claims queue; a customer still had to E-MAIL
 * their problem for someone to log it. These two columns close the loop:
 * a claim filed from the portal carries the account that filed it, so the
 * customer can watch its status, and a decision notifies them without
 * anyone drafting an e-mail.
 *
 * - `customer_id`: the portal account behind the claim. Nullable — claims
 *   logged by staff from an e-mail thread have no account, and that stays
 *   the common case for B2B partners who never use the portal.
 * - `source`: 'admin' (staff logged it) | 'portal' (customer filed it).
 *   Plain string, not an enum — the standing rule.
 *
 * Additive, guarded on hasTable/hasColumn; nothing existing read, altered
 * or backfilled (every existing row is staff-logged, and 'admin' is the
 * column default). Deploy-order safe via Claim::supportsCustomerLink() —
 * the portal endpoints answer "not available yet" and the admin queue
 * works exactly as before until this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('claims')) {
            return;
        }

        if (! Schema::hasColumn('claims', 'customer_id')) {
            Schema::table('claims', function (Blueprint $table) {
                $table->foreignId('customer_id')
                    ->nullable()
                    ->after('order_number')
                    ->constrained('customers')
                    ->nullOnDelete();
                $table->index(['customer_id', 'status']);
            });
        }

        if (! Schema::hasColumn('claims', 'source')) {
            Schema::table('claims', function (Blueprint $table) {
                $table->string('source', 10)->default('admin')->after('customer_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('claims')) {
            return;
        }

        if (Schema::hasColumn('claims', 'customer_id')) {
            Schema::table('claims', function (Blueprint $table) {
                $table->dropIndex(['customer_id', 'status']);
                $table->dropConstrainedForeignId('customer_id');
            });
        }

        if (Schema::hasColumn('claims', 'source')) {
            Schema::table('claims', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
