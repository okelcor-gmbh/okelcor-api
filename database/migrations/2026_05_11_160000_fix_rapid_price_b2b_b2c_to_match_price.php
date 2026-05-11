<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migration 150000 ran before price_b2b/price_b2c were added to its UPDATE,
     * so `price` is correctly discounted but both b2b/b2c fields still hold the
     * old pre-discount values. Set them equal to `price`.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE products
            SET    price_b2b  = price,
                   price_b2c  = price,
                   updated_at = NOW()
            WHERE  brand      = 'Rapid'
              AND  deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE products
            SET    price_b2b  = cost_price,
                   price_b2c  = cost_price,
                   updated_at = NOW()
            WHERE  brand      = 'Rapid'
              AND  deleted_at IS NULL
              AND  cost_price IS NOT NULL
        ");
    }
};
