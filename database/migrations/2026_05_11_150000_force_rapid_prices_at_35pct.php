<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The previous backfill migration (140000) recorded as run but prices
     * were not updated — the active Rapid promotion was not found at run time.
     *
     * This migration applies the same logic unconditionally: no promotion
     * lookup, 35% hardcoded as the stakeholder-confirmed current rate.
     */
    public function up(): void
    {
        // Save original Excel prices as cost_price (no-op if already set)
        DB::statement("
            UPDATE products
            SET    cost_price = price
            WHERE  brand      = 'Rapid'
              AND  deleted_at IS NULL
              AND  cost_price IS NULL
        ");

        // Apply 35% discount: price = cost_price × 0.65
        DB::statement("
            UPDATE products
            SET    price      = ROUND(cost_price * 0.65, 2),
                   updated_at = NOW()
            WHERE  brand      = 'Rapid'
              AND  deleted_at IS NULL
              AND  cost_price IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE products
            SET    price      = cost_price,
                   updated_at = NOW()
            WHERE  brand      = 'Rapid'
              AND  deleted_at IS NULL
              AND  cost_price IS NOT NULL
        ");
    }
};
