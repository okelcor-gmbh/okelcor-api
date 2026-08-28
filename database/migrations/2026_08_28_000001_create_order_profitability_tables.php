<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order profitability (Session 99, the finance discussion note).
 *
 * Two tables. `order_finance_records` is one row per order and holds the
 * REVENUE side: the finalized invoice the customer agreed to — number, amount,
 * the uploaded PDF — plus finance's verification signature over the whole
 * calculation. `order_cost_lines` is many rows per order and holds the COST
 * side: supplier invoices and the fee lines (Stripe, eBay, bank) that finance
 * wants subtracted before an order can be called profitable.
 *
 * Profit itself is never stored. It is revenue minus the sum of the lines,
 * computed in one service everywhere it is shown — a stored figure is a figure
 * that can disagree with the lines it claims to summarise.
 *
 * `kind` and `category` are strings, not ENUMs — see the order_logs.action
 * trap this project has walked into three times. Both are validated in the
 * controller against constants on the model.
 *
 * Two new tables. Nothing existing is read, altered or backfilled. Deploy-order
 * safe: every reader goes through OrderFinanceRecord::available(), which checks
 * for the table and answers with nothing until the migration has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_finance_records')) {
            Schema::create('order_finance_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();

                // Carried alongside the FK, same as trade_documents — the ref
                // is what finance types, quotes and exports.
                $table->string('order_ref', 30)->index();

                // The revenue invoice: finalized, agreed by the customer.
                $table->string('revenue_invoice_number', 50)->nullable();
                $table->decimal('revenue_amount', 12, 2)->nullable();
                $table->string('revenue_currency', 3)->default('EUR');
                $table->date('revenue_issued_on')->nullable();

                // When finance declared this the final figure, and when the
                // customer agreed to it. Two facts, two timestamps — an
                // invoice can be final without the agreement being recorded.
                $table->timestamp('revenue_finalized_at')->nullable();
                $table->timestamp('customer_agreed_at')->nullable();

                // The uploaded PDF, on the private disk — same columns as
                // finance_invoices so the two upload flows stay one shape.
                $table->string('revenue_file_path', 500)->nullable();
                $table->string('revenue_original_filename', 255)->nullable();
                $table->string('revenue_mime_type', 100)->nullable();
                $table->unsignedBigInteger('revenue_file_size')->nullable();
                $table->timestamp('revenue_uploaded_at')->nullable();

                $table->foreignId('revenue_set_by')->nullable()->constrained('admin_users')->nullOnDelete();

                // The sign-off. Withdrawn automatically whenever the money
                // changes underneath it — an approval of figures that have
                // since moved is worse than no approval at all.
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('verified_note', 500)->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_cost_lines')) {
            Schema::create('order_cost_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('order_ref', 30)->index();

                // 'supplier_invoice' or 'fee' — OrderCostLine::KINDS.
                $table->string('kind', 20);

                // For fees: stripe / ebay / bank / shipping / other
                // (OrderCostLine::FEE_CATEGORIES). Null on supplier invoices.
                $table->string('category', 30)->nullable();

                $table->string('supplier', 150)->nullable();

                // The supplier's own invoice number — free text, deliberately
                // not unique: two suppliers can both have an "INV-1".
                $table->string('reference', 60)->nullable();

                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('EUR');
                $table->date('incurred_on')->nullable();
                $table->string('notes', 500)->nullable();

                // The supplier's PDF, same shape as the revenue file above.
                $table->string('file_path', 500)->nullable();
                $table->string('original_filename', 255)->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamp('uploaded_at')->nullable();

                $table->foreignId('entered_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                $table->index(['order_id', 'kind']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cost_lines');
        Schema::dropIfExists('order_finance_records');
    }
};
