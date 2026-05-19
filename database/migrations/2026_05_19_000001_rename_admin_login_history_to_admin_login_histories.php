<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_login_histories')) {
            // Already correct — nothing to do.
            return;
        }

        if (Schema::hasTable('admin_login_history')) {
            // Rename misnamed table, preserving all existing rows.
            Schema::rename('admin_login_history', 'admin_login_histories');
            return;
        }

        // Neither table exists — create from scratch.
        Schema::create('admin_login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('admin_email', 255);
            $table->boolean('success')->default(false);
            $table->boolean('two_fa_used')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_id', 'created_at']);
            $table->index(['success', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('admin_login_histories') && !Schema::hasTable('admin_login_history')) {
            Schema::rename('admin_login_histories', 'admin_login_history');
        }
    }
};
