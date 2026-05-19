<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Additive-only ENUM change — existing rows are untouched.
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM('pending','confirmed','processing','shipped','delivered','cancelled','awaiting_proforma')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // Remove awaiting_proforma — only safe if no rows currently carry that value.
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM('pending','confirmed','processing','shipped','delivered','cancelled')
            NOT NULL DEFAULT 'pending'
        ");
    }
};
