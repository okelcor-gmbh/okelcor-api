<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Itemized invoice lines gain a per-line invoice number, and the party
 * vocabulary grows credit_note and cancelled (user's ask, 2026-09-09).
 * party_type was VARCHAR(10), too short for 'credit_note' (11 chars),
 * so it widens to 20 on MySQL; SQLite's length is advisory already.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_order_lines')) {
            return;
        }

        if (! Schema::hasColumn('sales_order_lines', 'invoice_no')) {
            Schema::table('sales_order_lines', function (Blueprint $table) {
                $table->string('invoice_no', 50)->nullable()->after('party_name');
            });
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sales_order_lines MODIFY COLUMN party_type VARCHAR(20) NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_order_lines')) {
            return;
        }

        if (Schema::hasColumn('sales_order_lines', 'invoice_no')) {
            Schema::table('sales_order_lines', function (Blueprint $table) {
                $table->dropColumn('invoice_no');
            });
        }
        // party_type stays at 20: narrowing could truncate stored values.
    }
};
