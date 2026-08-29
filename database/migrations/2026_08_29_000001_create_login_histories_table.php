<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer login-attempt log (Session 104) — a table shipped code has
 * been writing to since the customer portal launched, and which has never
 * existed on production (Known Gap since Session 70).
 *
 * Every write sits in a bare catch in CustomerAuthController, so the miss
 * was invisible — with two real consequences:
 *
 *   1. The 10-failed-logins-in-an-hour auto-suspend has NEVER fired: its
 *      counting query throws into the same catch. The 5-failure lockout
 *      still worked (it reads customers.failed_login_count), but the
 *      distributed-attempt backstop did not exist.
 *   2. When a customer reports being locked out, there is no record of the
 *      attempts to confirm it from our side — and the admin panel's
 *      login-history view threw a PDOException (seen live in the logs).
 *
 * Creating the table is the whole fix; the reading and writing code has
 * been correct all along. One NEW table; nothing existing is read, altered
 * or backfilled. Deploy-order safe in both directions — the writers catch,
 * so code and migration can land in either order.
 *
 * Columns mirror what LoginHistory::create() has always written. The model
 * sets $timestamps = false and stamps created_at itself, so there is no
 * updated_at — an attempt log row is never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_histories')) {
            return;
        }

        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete: an attempt log for a deleted account has
            // nothing to defend or explain — unlike order/audit trails,
            // which survive their subject on purpose.
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->boolean('success');
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('location', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            // The auto-suspend query: failed attempts for one customer in
            // the past hour.
            $table->index(['customer_id', 'success', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
