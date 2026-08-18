<?php

use App\Models\SecurityEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens `security_events.type` for the email-verification events, and stops
 * this list being restated by hand (Session 91).
 *
 * Same change `order_logs.action` got in Session 83, applied to the column that
 * was explicitly left out of it and has sat in Known Gaps ever since. The ENUM
 * is now built from `SecurityEvent::TYPES`, so the schema and the code that
 * writes to it cannot drift, and a test asserts every literal passed to
 * `SecurityEventService::log()` in `app/` appears in that constant.
 *
 * This column fails harder than the order-log one did. `SecurityEventService`
 * does not wrap its insert in a try/catch, so on strict MySQL a missing type
 * does not lose an audit row quietly — it throws, and fails the customer action
 * that triggered it. Writing `email_verified` from the password-reset path
 * without this migration would break completing a password reset for everyone.
 *
 * **Not deploy-order safe, deliberately.** Apply this before or with the code.
 *
 * Skipped on non-MySQL so the sqlite harness is unaffected.
 */
return new class extends Migration
{
    /** Added here. Named so `down()` knows what it would be truncating. */
    private const ADDED = [
        'email_verified',
        'email_verification_sent',
        'email_verified_by_admin',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement($this->enumSql(SecurityEvent::TYPES));
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $orphans = DB::table('security_events')->whereIn('type', self::ADDED)->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$orphans} security_events row(s) use a type this migration added. "
                . 'Reverting the ENUM would silently truncate an audit trail.'
            );
        }

        DB::statement($this->enumSql(array_values(array_diff(SecurityEvent::TYPES, self::ADDED))));
    }

    private function enumSql(array $values): string
    {
        $list = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));

        return "ALTER TABLE `security_events` MODIFY COLUMN `type` ENUM({$list}) NOT NULL";
    }
};
