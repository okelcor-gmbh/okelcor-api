<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Correct released_at for reverse-charge invoices that were incorrectly
     * released by the 2026_05_08_000004 backfill when the linked declaration
     * status was 'signed' (not yet 'acknowledged').
     *
     * Compliance rule: invoice is only released after admin acknowledgement.
     * Signing alone is not sufficient.
     */
    public function up(): void
    {
        // Null out released_at for RC invoices whose declaration is signed but
        // not yet acknowledged — they should not be visible to the customer.
        DB::statement("
            UPDATE invoices i
            INNER JOIN eu_declarations ed ON ed.order_ref = i.order_ref
            SET i.released_at = NULL
            WHERE i.is_reverse_charge = 1
              AND i.released_at IS NOT NULL
              AND ed.status = 'signed'
              AND NOT EXISTS (
                  SELECT 1
                  FROM eu_declarations ed2
                  WHERE ed2.order_ref = i.order_ref
                    AND ed2.status = 'acknowledged'
              )
        ");
    }

    public function down(): void
    {
        // Non-reversible: we cannot recover what signed_at value was used
        // in the original (incorrect) backfill without re-running that migration.
    }
};
