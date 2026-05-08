<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('pdf_url');
            $table->index('released_at');
        });

        // Backfill released_at for all existing invoices.
        //
        // Logic (single-pass LEFT JOIN):
        //   - Non-reverse-charge invoices → release immediately at issued_at
        //   - Reverse-charge + linked declaration is signed/acknowledged → release at signed_at (or NOW() if null)
        //   - Reverse-charge + no signed declaration → leave NULL (customer cannot download yet)
        //
        // eu_declarations.order_ref is used for the join because invoice_id may be null
        // on declarations created before Phase 2B-2 linked invoices explicitly.
        DB::statement("
            UPDATE invoices i
            LEFT JOIN eu_declarations ed
                ON  ed.order_ref = i.order_ref
                AND ed.status IN ('signed', 'acknowledged')
            SET i.released_at = CASE
                WHEN i.is_reverse_charge = 0 OR i.is_reverse_charge IS NULL
                    THEN i.issued_at
                WHEN ed.id IS NOT NULL
                    THEN COALESCE(ed.signed_at, NOW())
                ELSE
                    NULL
            END
        ");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['released_at']);
            $table->dropColumn('released_at');
        });
    }
};
