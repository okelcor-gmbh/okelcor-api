<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Sales & Orders board's "Tyres" category splits into "New Tyres" and
 * "Used Tyres" (user's ask, 2026-09-09). Existing rows all become
 * "New Tyres" — the board never distinguished, and new tyres are the
 * overwhelming default; finance can flip individual rows to Used.
 * Plain string column, so this is data + default only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_order_entries') || ! Schema::hasColumn('sales_order_entries', 'category')) {
            return;
        }

        DB::table('sales_order_entries')->where('category', 'Tyres')->update(['category' => 'New Tyres']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales_order_entries MODIFY COLUMN category VARCHAR(20) NOT NULL DEFAULT 'New Tyres'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_order_entries') || ! Schema::hasColumn('sales_order_entries', 'category')) {
            return;
        }

        DB::table('sales_order_entries')->whereIn('category', ['New Tyres', 'Used Tyres'])->update(['category' => 'Tyres']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales_order_entries MODIFY COLUMN category VARCHAR(20) NOT NULL DEFAULT 'Tyres'");
        }
    }
};
