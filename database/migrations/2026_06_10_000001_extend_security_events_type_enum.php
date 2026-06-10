<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen security_events.type to cover the customer-lifecycle event types the
 * application already logs (CRM-1 onboarding, CRM-8 buyer approval, lead
 * conversion, and the admin "Add Customer" flow).
 *
 * Before this, those types were not in the enum: on a MySQL server in
 * non-strict mode the audit row was silently written with an empty type, and
 * in strict mode the insert failed outright. This restores a faithful audit
 * trail without touching any existing rows.
 */
return new class extends Migration
{
    /** Full set of allowed values after this migration. */
    private array $types = [
        // Original (auth / account security)
        'failed_login', 'suspicious_activity', 'new_registration',
        'password_reset', 'account_changes', 'account_lockout',
        'account_unlock', 'account_suspend', 'account_ban',
        // Customer lifecycle (CRM-1 / CRM-8 / lead conversion / admin onboarding)
        'customer_pending_review_created', 'customer_activated',
        'customer_created', 'customer_invited', 'customer_approved',
        'customer_rejected', 'customer_blocked', 'lead_converted_to_customer',
    ];

    /** Original enum values (for rollback). */
    private array $original = [
        'failed_login', 'suspicious_activity', 'new_registration',
        'password_reset', 'account_changes', 'account_lockout',
        'account_unlock', 'account_suspend', 'account_ban',
    ];

    public function up(): void
    {
        // Raw MODIFY COLUMN is MySQL-specific; skip on other drivers (e.g. the
        // SQLite test harness) which use a different migration path.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement($this->enumSql($this->types));
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement($this->enumSql($this->original));
    }

    private function enumSql(array $values): string
    {
        $list = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));

        return "ALTER TABLE `security_events` MODIFY COLUMN `type` ENUM({$list}) NOT NULL";
    }
};
