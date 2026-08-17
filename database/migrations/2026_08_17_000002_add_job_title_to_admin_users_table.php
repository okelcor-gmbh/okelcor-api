<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person actually does, separately from what the system lets them touch.
 *
 * `admin_users.role` is a permission set, and it has never been a job title.
 * Two order managers hold `admin` because they also need customers, campaigns
 * and quote requests; the person running operations holds `admin` for the same
 * reason. Reading the role as the job labels all three "Admin", which is wrong
 * about every one of them — and a contribution report that mislabels the people
 * in it is worse than no report.
 *
 * So the ledger stops inferring the job from the permission and records it.
 * `job_title` is free text rather than an enum on purpose: this business has
 * already been bitten once by a column that could not hold the values its own
 * code used (see the `admin_users.role` note in PROGRESS), and job titles change
 * more often than permission sets do.
 *
 * `admin_job_title` on `staff_activities` is the same snapshot reasoning the
 * name and role already follow — what someone did last quarter is a statement
 * about who they were then, and reading it live would relabel their history the
 * day they change job.
 *
 * Both columns are additive, guarded and nullable. Nothing existing is read or
 * rewritten, and every code path falls back to a readable label derived from the
 * role until a title is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_users') && ! Schema::hasColumn('admin_users', 'job_title')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->string('job_title', 60)->nullable()->after('role');
            });
        }

        // Guarded against the ledger's own migration not having run yet — these
        // two ship together but must not depend on each other's order.
        if (Schema::hasTable('staff_activities') && ! Schema::hasColumn('staff_activities', 'admin_job_title')) {
            Schema::table('staff_activities', function (Blueprint $table) {
                $table->string('admin_job_title', 60)->nullable()->after('admin_role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('staff_activities') && Schema::hasColumn('staff_activities', 'admin_job_title')) {
            Schema::table('staff_activities', function (Blueprint $table) {
                $table->dropColumn('admin_job_title');
            });
        }

        if (Schema::hasTable('admin_users') && Schema::hasColumn('admin_users', 'job_title')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->dropColumn('job_title');
            });
        }
    }
};
