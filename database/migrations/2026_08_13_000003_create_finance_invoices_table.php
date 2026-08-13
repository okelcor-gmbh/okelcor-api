<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices as the finance system (sevDesk) has them, entered by hand.
 *
 * Finance raises invoices in sevDesk; this API raises its own. The board on the
 * finance director's sketch puts the two counts side by side because the useful
 * number is neither of them — it is the difference. Five here and four there
 * means one order was invoiced in a system nobody else can see, or one invoice
 * exists that finance has not booked.
 *
 * This is deliberately NOT an integration. No sevDesk credentials, no sync, no
 * webhook: finance types what they have. An integration that silently stopped
 * syncing would make the two columns agree by accident, which is the one
 * failure this board exists to catch.
 *
 * `order_ref` is nullable and unvalidated against `orders` on purpose — an
 * invoice finance cannot match to an order here is exactly the row worth
 * recording, and refusing it would hide the discrepancy rather than surface it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_invoices')) {
            return;
        }

        Schema::create('finance_invoices', function (Blueprint $table) {
            $table->id();

            // Which external system this came from. One value today; a column
            // rather than an assumption because "the finance system" has
            // changed before at companies this size.
            $table->string('system', 30)->default('sevdesk');

            // The number as it reads in sevDesk. Unique per system so the same
            // invoice cannot be entered twice and inflate the comparison —
            // which would make the two sides agree when they do not.
            $table->string('external_number', 60);

            $table->string('order_ref', 30)->nullable()->index();
            $table->string('invoice_number', 50)->nullable()->index();

            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->date('issued_on')->index();

            // 'normal' | 'ebay'. Recorded rather than derived: an invoice that
            // matches no order here has no order to derive a channel from, and
            // that is the case this table is for.
            $table->string('channel', 20)->default('normal')->index();

            $table->string('notes', 500)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['system', 'external_number'], 'finance_invoices_system_number_unique');
            $table->index(['issued_on', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_invoices');
    }
};
