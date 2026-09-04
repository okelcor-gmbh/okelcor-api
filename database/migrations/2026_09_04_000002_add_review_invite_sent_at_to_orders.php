<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One review invite per order, ever (Session 118).
 *
 * The stamp is what makes the invite idempotent: flipping an order to
 * delivered, back, and to delivered again must not e-mail the customer
 * twice. Additive, nullable, guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'review_invite_sent_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('review_invite_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'review_invite_sent_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('review_invite_sent_at');
        });
    }
};
