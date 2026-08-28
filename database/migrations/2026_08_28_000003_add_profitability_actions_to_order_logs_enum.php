<?php

use App\Models\OrderLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens `order_logs.action` for the profitability trail (Session 99).
 *
 * Third widening built from `OrderLog::ACTIONS` rather than a hand-copied
 * list — see 2026_08_13_000004 for why that list stopped being restated by
 * hand, and what it cost the payment milestone history when it was.
 *
 * These actions record money being attached to an order after the fact —
 * the revenue invoice, supplier costs, fees — and finance's verification of
 * the resulting profit figure. The verification-withdrawn action matters
 * most: it is written automatically whenever the figures move under a
 * standing verification, and is the only evidence that an approved number
 * stopped being the approved number.
 *
 * Skipped on non-MySQL so the sqlite harness is unaffected.
 */
return new class extends Migration
{
    /** Added here. Named so `down()` knows what it would be truncating. */
    private const ADDED = [
        'revenue_invoice_set',
        'cost_line_added',
        'cost_line_updated',
        'cost_line_removed',
        'profitability_verified',
        'profitability_verification_withdrawn',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = implode(',', array_map(fn ($v) => "'{$v}'", OrderLog::ACTIONS));

        DB::statement("ALTER TABLE order_logs MODIFY COLUMN action ENUM({$values}) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $orphans = DB::table('order_logs')->whereIn('action', self::ADDED)->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$orphans} order_logs row(s) use an action this migration added. "
                . 'Reverting the ENUM would silently truncate an append-only audit trail.'
            );
        }

        $keep   = array_values(array_diff(OrderLog::ACTIONS, self::ADDED));
        $values = implode(',', array_map(fn ($v) => "'{$v}'", $keep));

        DB::statement("ALTER TABLE order_logs MODIFY COLUMN action ENUM({$values}) NOT NULL");
    }
};
