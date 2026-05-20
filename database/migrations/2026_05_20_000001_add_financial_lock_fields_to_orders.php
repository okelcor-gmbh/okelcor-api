<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('financials_locked_at')->nullable()->after('eta');
            $table->foreignId('financials_locked_by')
                ->nullable()
                ->constrained('admin_users')
                ->nullOnDelete()
                ->after('financials_locked_at');
            $table->string('financials_lock_reason', 255)->nullable()->after('financials_locked_by');
            $table->boolean('financials_revision_required')->default(false)->after('financials_lock_reason');
            $table->text('financials_revision_reason')->nullable()->after('financials_revision_required');
            $table->foreignId('financials_revision_requested_by')
                ->nullable()
                ->constrained('admin_users')
                ->nullOnDelete()
                ->after('financials_revision_reason');
            $table->timestamp('financials_revision_requested_at')->nullable()->after('financials_revision_requested_by');
            $table->json('financials_revision_changes')->nullable()->after('financials_revision_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['financials_locked_by']);
            $table->dropForeign(['financials_revision_requested_by']);
            $table->dropColumns([
                'financials_locked_at',
                'financials_locked_by',
                'financials_lock_reason',
                'financials_revision_required',
                'financials_revision_reason',
                'financials_revision_requested_by',
                'financials_revision_requested_at',
                'financials_revision_changes',
            ]);
        });
    }
};
