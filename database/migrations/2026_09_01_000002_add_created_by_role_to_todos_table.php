<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a to-do came from (Session 109).
 *
 * The shared list mixes every department's requests together, so a row reads
 * as one more line from a name you may not place. Stamping the creator's role
 * lets it read "from Finance".
 *
 * **Stamped, not derived.** Two reasons, both of which lose information if the
 * label is computed from the creator's CURRENT role at read time:
 *
 *  1. `todos.created_by` is `nullOnDelete`. Delete an admin account and every
 *     to-do that person ever raised loses its origin entirely.
 *  2. People change role — there is an outstanding task in PROGRESS.md to move
 *     the marketer from `editor` to `marketing`. Deriving live would silently
 *     relabel all of his historical to-dos from Content to Marketing, which
 *     rewrites who asked for what.
 *
 * The role is stamped; the DEPARTMENT LABEL is still derived from it at read
 * time via `AdminPermissions::departmentFor()`, so the wording can be
 * corrected later without a data migration. The fact is frozen, the
 * presentation is not.
 *
 * Additive, nullable, guarded. The backfill only writes rows where the column
 * is still null and a creator can be resolved, so it is re-runnable and cannot
 * overwrite a stamp that is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('todos')) {
            return;
        }

        if (! Schema::hasColumn('todos', 'created_by_role')) {
            Schema::table('todos', function (Blueprint $table) {
                $table->string('created_by_role', 30)->nullable()->after('created_by');
                $table->index('created_by_role');
            });
        }

        // Existing rows predate the stamp, so their origin can only come from
        // the creator's role as it stands now — the best available answer,
        // and the one that is correct for every row nobody has re-roled yet.
        // Done in PHP rather than a JOIN UPDATE so it runs identically on
        // MySQL and on the sqlite test harness.
        if (! Schema::hasTable('admin_users')) {
            return;
        }

        $roles = DB::table('admin_users')->pluck('role', 'id');

        DB::table('todos')
            ->whereNull('created_by_role')
            ->whereNotNull('created_by')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($roles) {
                foreach ($rows as $row) {
                    $role = $roles[$row->created_by] ?? null;

                    if ($role === null) {
                        continue;
                    }

                    DB::table('todos')->where('id', $row->id)->update(['created_by_role' => $role]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('todos') || ! Schema::hasColumn('todos', 'created_by_role')) {
            return;
        }

        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['created_by_role']);
            $table->dropColumn('created_by_role');
        });
    }
};
