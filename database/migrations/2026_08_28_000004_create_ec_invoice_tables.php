<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EC Invoice List — the Zusammenfassende Meldung (ZM) portal (Session 100,
 * from finance's File6.html mockup).
 *
 * The German EC Sales List (§ 18a UStG): per reporting period, transactions
 * are grouped by (EU country, customer VAT ID, transaction type), and each
 * group itemizes the invoices behind its aggregate — with the invoice PDF and
 * the delivery proof attached, because the aggregate is what BZSt receives
 * and the itemization is what an auditor asks for.
 *
 * Three tables. `ec_invoice_periods` carries the filing status of one period
 * ('2026-Q1' or '2026-05'); `ec_invoice_groups` is one ZM line (country ×
 * VAT ID × type within a period); `ec_invoice_lines` is one invoice inside a
 * group, with its assignee and task status — the chase mechanism, same shape
 * as the finance snapshot's.
 *
 * Group totals are never stored — always the sum of the lines, computed in
 * the controller. `transaction_type` and `task_status` are strings validated
 * against model constants, not ENUMs (the order_logs.action trap).
 *
 * Three NEW tables; nothing existing is read, altered or backfilled.
 * Deploy-order safe: readers go through EcInvoiceGroup::available().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ec_invoice_periods')) {
            Schema::create('ec_invoice_periods', function (Blueprint $table) {
                $table->id();

                // '2026-Q1' or '2026-05' — quarter or month, the two shapes
                // § 18a filing actually uses.
                $table->string('period', 8)->unique();

                // draft → ready → submitted (EcInvoicePeriod::STATUSES).
                $table->string('status', 20)->default('draft');
                $table->timestamp('submitted_at')->nullable();

                $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ec_invoice_groups')) {
            Schema::create('ec_invoice_groups', function (Blueprint $table) {
                $table->id();
                $table->string('period', 8)->index();

                $table->string('country_code', 2);
                $table->string('customer_vat_id', 20);

                // goods | services | triangular (EcInvoiceGroup::TYPES) — maps
                // to ELSTER Art L | S | D.
                $table->string('transaction_type', 20);

                $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                // The same customer twice in one period would double its ZM
                // line — a duplicate is a friendly 422, not a second row.
                $table->unique(
                    ['period', 'country_code', 'customer_vat_id', 'transaction_type'],
                    'ec_invoice_groups_unique'
                );
            });
        }

        if (! Schema::hasTable('ec_invoice_lines')) {
            Schema::create('ec_invoice_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('ec_invoice_groups')->cascadeOnDelete();

                $table->string('invoice_number', 50);
                $table->date('invoice_date')->nullable();
                $table->decimal('amount', 12, 2)->default(0);

                // The chase: who is responsible for completing this line's
                // paperwork. The tag notifies them and lands in their My Work,
                // same as finance snapshot items; the display name survives an
                // account deletion.
                $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('person_name', 100)->nullable();

                // complete | pending_doc | review (EcInvoiceLine::STATUSES).
                $table->string('task_status', 20)->default('pending_doc');

                // The invoice PDF, private disk — same columns as every other
                // finance upload.
                $table->string('file_path', 500)->nullable();
                $table->string('original_filename', 255)->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamp('uploaded_at')->nullable();

                // The delivery proof (CMR, POD, signed timesheet) — the
                // document that makes a zero-rated intra-EU supply defensible.
                $table->string('proof_path', 500)->nullable();
                $table->string('proof_original_filename', 255)->nullable();
                $table->string('proof_mime_type', 100)->nullable();
                $table->unsignedBigInteger('proof_file_size')->nullable();
                $table->timestamp('proof_uploaded_at')->nullable();

                $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                $table->index(['assigned_admin_id', 'task_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_invoice_lines');
        Schema::dropIfExists('ec_invoice_groups');
        Schema::dropIfExists('ec_invoice_periods');
    }
};
