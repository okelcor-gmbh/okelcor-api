<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user permission overrides on top of the role.
 *
 * The business kept needing "this one person also handles X" and the only
 * lever was changing their whole role — which swaps dozens of permissions
 * to move one. These two columns let a super admin add or remove single
 * permissions per person while the role stays the honest baseline.
 *
 * Both are JSON arrays of permission keys from AdminPermissions::MAP.
 * Resolution lives in AdminUser::effectivePermissions():
 * (role permissions + grants) − revokes, with super_admin immune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            if (! Schema::hasColumn('admin_users', 'permission_grants')) {
                $table->json('permission_grants')->nullable()->after('role');
            }
            if (! Schema::hasColumn('admin_users', 'permission_revokes')) {
                $table->json('permission_revokes')->nullable()->after('permission_grants');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            if (Schema::hasColumn('admin_users', 'permission_revokes')) {
                $table->dropColumn('permission_revokes');
            }
            if (Schema::hasColumn('admin_users', 'permission_grants')) {
                $table->dropColumn('permission_grants');
            }
        });
    }
};
