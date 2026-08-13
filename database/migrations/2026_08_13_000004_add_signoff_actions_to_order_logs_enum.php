<?php

use App\Models\OrderLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens `order_logs.action` for the dual sign-off trail — and stops the list
 * being restated by hand.
 *
 * Every previous widening (2026_07_15_000001, 2026_07_17_120845,
 * 2026_08_10_000001, 2026_08_11_000001) carried its own full copy of the value
 * list, because MySQL requires the complete set on every MODIFY COLUMN. That is
 * the mechanism behind the longest-standing High gap in this project: shipped
 * code writes an action the column rejects, the write is inside a try/catch
 * that logs a warning and continues, and the audit row is never created. Three
 * separate instances have been found that way, one of which destroyed the
 * payment milestone history for every order on production.
 *
 * This one builds the ENUM from OrderLog::ACTIONS. The list now lives beside
 * the code that writes it, a test asserts every literal in `app/` appears in
 * it, and a future widening is a constant edit plus a one-line migration that
 * cannot forget an existing value.
 *
 * Sign-off actions matter more than most: a dual approval whose audit row
 * silently vanished would be a compliance control that reports itself as
 * working while recording nothing.
 *
 * Skipped on non-MySQL so the sqlite harness is unaffected.
 */
return new class extends Migration
{
    /** Added here. Named so `down()` knows what it would be truncating. */
    private const ADDED = [
        'signoff_given',
        'signoff_revoked',
        'signoff_bypassed',
        'document_gate_overridden',
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
