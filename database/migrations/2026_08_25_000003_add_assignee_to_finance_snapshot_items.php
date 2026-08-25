<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Person handling" becomes a real staff tag, not just a typed name.
 *
 * The free-text `person` column stays — it is the display name and covers
 * people who are not system users — but `assigned_admin_id` links a record
 * to an actual admin account, which is what lets the system notify them,
 * surface the record in their My Work queue, and remind them when it is due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_snapshot_items', function (Blueprint $table) {
            if (! Schema::hasColumn('finance_snapshot_items', 'assigned_admin_id')) {
                $table->foreignId('assigned_admin_id')->nullable()->after('person')
                    ->constrained('admin_users')->nullOnDelete();
                $table->index('assigned_admin_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('finance_snapshot_items', function (Blueprint $table) {
            if (Schema::hasColumn('finance_snapshot_items', 'assigned_admin_id')) {
                $table->dropConstrainedForeignId('assigned_admin_id');
            }
        });
    }
};
