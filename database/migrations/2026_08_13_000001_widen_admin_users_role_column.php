<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `admin_users.role` becomes a plain string.
 *
 * This has been the top Known Gap since Session 52. The column is a MySQL ENUM
 * allowing only super_admin/admin/editor/order_manager, while AdminPermissions
 * has referenced sales_manager, support, content_manager and viewer throughout
 * — roles that cannot be stored under strict mode, so creating an admin with
 * one fails outright. Three sessions have since granted a new permission to a
 * deliberately narrowed role list with a comment saying to widen it here first
 * (partner_sales.verify in 73, analytics.view in 79).
 *
 * It stops being a nuisance and becomes a blocker with dual sign-off: the
 * finance half of an order confirmation cannot be signed by a role the database
 * refuses to hold.
 *
 * VARCHAR rather than a wider ENUM, deliberately. Every ENUM widening in this
 * codebase has to restate the full value list and is one forgotten entry away
 * from truncating data — that pattern has now been paid for four times
 * (order_logs twice, security_events, this). The authoritative list is
 * AdminPermissions::ROLES, which the create and update endpoints already
 * validate against via Rule::in, so the constraint moves to the one place that
 * was already enforcing it rather than being duplicated in the schema.
 *
 * Data-safe in both directions of reading: every existing value is a valid
 * string, so no row changes and no row can be lost. `down()` refuses rather
 * than running if any admin holds a role the old ENUM could not store —
 * reverting would silently blank that account's role and lock the person out.
 */
return new class extends Migration
{
    /** What the ENUM allowed before this migration. */
    private const LEGACY_ROLES = ['super_admin', 'admin', 'editor', 'order_manager'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasColumn('admin_users', 'role')) {
            return;
        }

        DB::statement("ALTER TABLE admin_users MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'viewer'");

        // The ENUM was doing double duty as an index hint on some engines; make
        // the index explicit now that it is a plain column. Guarded because a
        // re-run must not fail on an index that already exists.
        $existing = collect(DB::select('SHOW INDEX FROM admin_users'))
            ->pluck('Key_name')
            ->contains('admin_users_role_idx');

        if (! $existing) {
            DB::statement('CREATE INDEX admin_users_role_idx ON admin_users (role)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $stranded = DB::table('admin_users')
            ->whereNotIn('role', self::LEGACY_ROLES)
            ->pluck('email')
            ->all();

        if ($stranded !== []) {
            throw new RuntimeException(
                'Refusing to narrow admin_users.role: these accounts hold a role the old ENUM cannot store, '
                . 'and reverting would blank it and lock them out — ' . implode(', ', $stranded)
                . '. Reassign them first.'
            );
        }

        $values = "'" . implode("','", self::LEGACY_ROLES) . "'";

        DB::statement("ALTER TABLE admin_users MODIFY COLUMN role ENUM({$values}) NOT NULL DEFAULT 'editor'");
    }
};
