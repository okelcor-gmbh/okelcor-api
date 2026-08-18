<?php

use App\Models\OrderLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens `order_logs.action` for `payment_state_corrected` (Session 90).
 *
 * Second widening built from `OrderLog::ACTIONS` rather than a hand-copied
 * list — see 2026_08_13_000004 for why that list stopped being restated by
 * hand, and what it cost the payment milestone history when it was.
 *
 * This value matters more than most. `PaymentStateCorrectionService` is the
 * only thing in the codebase that can move an order's payment state backwards,
 * and its whole claim to being safe in an order manager's hands is that it
 * cannot be done quietly. Its log write is therefore the one in this project
 * NOT wrapped in a try/catch: if the column rejected the action the correction
 * rolls back rather than happening unrecorded. Until this migration runs, that
 * means the endpoint refuses to work on MySQL rather than working silently —
 * which is the intended failure of the two.
 *
 * Skipped on non-MySQL so the sqlite harness is unaffected.
 */
return new class extends Migration
{
    /** Added here. Named so `down()` knows what it would be truncating. */
    private const ADDED = [
        'payment_state_corrected',
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
