<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `finance_invoices` becomes the invoice register: every invoice, whatever
 * produced it, in one record format.
 *
 * The table was built for one job — what finance types in from sevDesk — and
 * the reconciliation compared that against our own `invoices` table. Those are
 * two different shapes, so matching them meant translating between them, and a
 * translation is where a mismatch hides.
 *
 * Two additions:
 *
 *   1. A file. Finance holds the sevDesk PDF and had nowhere to put it, so the
 *      document and the record of it lived in different systems.
 *   2. `system` widens from sevdesk/other to also mean `okelcor` — a row this
 *      API registered for an invoice it produced or an order manager sent to a
 *      customer. Both sides of the comparison then sit in one table with the
 *      same columns and the same matching keys, which is the whole of what
 *      "so there are no mismatch" asks for.
 *
 * Additive and guarded throughout. Nothing existing is read, altered or
 * backfilled: rows already in the table are sevDesk entries and stay exactly
 * as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_invoices')) {
            return;
        }

        Schema::table('finance_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('finance_invoices', 'file_path')) {
                // Private disk, like every trade document — an invoice is not
                // a public asset and must not be reachable by guessing a URL.
                $table->string('file_path', 500)->nullable()->after('notes');
                $table->string('original_filename', 255)->nullable()->after('file_path');
                $table->string('mime_type', 100)->nullable()->after('original_filename');
                $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
                $table->timestamp('uploaded_at')->nullable()->after('file_size');
            }

            if (! Schema::hasColumn('finance_invoices', 'source_type')) {
                // What produced the row on our own side — 'invoice' for a tax
                // invoice this API raised, 'trade_document' for one an order
                // manager issued or uploaded against an order. Null for
                // anything finance typed in. Kept so an auto-registered row can
                // be traced back to the thing it describes rather than being
                // an orphan number.
                $table->string('source_type', 30)->nullable()->after('system');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');

                $table->index(['source_type', 'source_id'], 'finance_invoices_source_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_invoices')) {
            return;
        }

        Schema::table('finance_invoices', function (Blueprint $table) {
            foreach ([
                'file_path', 'original_filename', 'mime_type', 'file_size',
                'uploaded_at', 'source_type', 'source_id',
            ] as $column) {
                if (Schema::hasColumn('finance_invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
