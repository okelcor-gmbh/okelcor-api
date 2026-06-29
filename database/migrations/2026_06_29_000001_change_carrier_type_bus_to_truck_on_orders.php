<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Okelcor does not run bus freight — replace the `bus` carrier type with
 * `truck` (truck/road haulage). Migrates any existing `bus` rows to `truck`,
 * then narrows the enum. MySQL-only (raw enum DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('orders', 'carrier_type')) {
            return;
        }

        // 1. Widen to include both old + new so the data update can't truncate.
        DB::statement("ALTER TABLE orders MODIFY COLUMN carrier_type ENUM('sea','air','dhl','road','bus','truck') NULL");
        // 2. Move existing data.
        DB::table('orders')->where('carrier_type', 'bus')->update(['carrier_type' => 'truck']);
        // 3. Drop the retired value.
        DB::statement("ALTER TABLE orders MODIFY COLUMN carrier_type ENUM('sea','air','dhl','road','truck') NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('orders', 'carrier_type')) {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN carrier_type ENUM('sea','air','dhl','road','bus','truck') NULL");
        DB::table('orders')->where('carrier_type', 'truck')->update(['carrier_type' => 'bus']);
        DB::statement("ALTER TABLE orders MODIFY COLUMN carrier_type ENUM('sea','air','dhl','road','bus') NULL");
    }
};
