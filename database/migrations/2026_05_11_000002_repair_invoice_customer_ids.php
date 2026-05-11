<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Repair invoices where customer_id is NULL or points to the wrong customer.
     *
     * Root cause: InvoiceService looks customers up by order.customer_email.
     * If a customer account was created after the invoice, or if there are
     * duplicate/imported Customer rows, the stored customer_id may not match
     * the account the customer actually logs in with.
     *
     * Fix: re-derive customer_id from the authoritative chain
     *   invoices.order_ref → orders.customer_email → customers.id
     */
    public function up(): void
    {
        DB::statement("
            UPDATE invoices i
            INNER JOIN orders o      ON o.ref   = i.order_ref
            INNER JOIN customers c   ON c.email = o.customer_email
            SET i.customer_id = c.id
            WHERE i.customer_id IS NULL
               OR i.customer_id != c.id
        ");
    }

    public function down(): void
    {
        // Non-reversible: original (incorrect) customer_id values are not stored.
    }
};
