<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The register learns which side of an order's profit a row sits on.
 *
 * Finance wants order tracking to know "the invoice the customer agreed to —
 * that's our revenue invoice", and then to see the supplier invoices against
 * the same order. Both are invoices, both arrive as a number plus a PDF, and
 * the register already stores exactly that — so rather than a second table
 * with the same columns, each row gains a `role`:
 *
 *   register — what the table has always held: an entry for reconciliation.
 *              Every existing row, and the default for new ones.
 *   revenue  — the customer-agreed invoice for an order. Its amount IS the
 *              order's revenue once finalized.
 *   supplier — an invoice from a supplier against the order: a cost.
 *
 * `finalized_at` is the customer-agreement moment. Profitability counts a
 * revenue invoice only once finalized, and finalizing locks the row's money
 * fields — a figure that feeds a profit report must not drift after the
 * customer agreed to it.
 *
 * Additive and guarded throughout; existing rows keep meaning what they meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_invoices')) {
            return;
        }

        Schema::table('finance_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('finance_invoices', 'role')) {
                $table->string('role', 20)->default('register')->after('channel');
                $table->string('supplier_name', 150)->nullable()->after('role');
                $table->timestamp('finalized_at')->nullable()->after('supplier_name');
                $table->foreignId('finalized_by')->nullable()->after('finalized_at')
                    ->constrained('admin_users')->nullOnDelete();

                $table->index('role', 'finance_invoices_role_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_invoices') || ! Schema::hasColumn('finance_invoices', 'role')) {
            return;
        }

        Schema::table('finance_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropIndex('finance_invoices_role_idx');
            $table->dropColumn(['role', 'supplier_name', 'finalized_at']);
        });
    }
};
