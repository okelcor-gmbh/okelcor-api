<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tyre passport" — per-product condition/traceability data for graded stock
 * (primarily Used, but valid on any type). Genuinely new capability: none of
 * this existed anywhere in the ops workflow before, so every column is
 * nullable and the public payload omits the whole block until an admin fills
 * it in. Nothing is derived or guessed.
 *
 * condition_grade is a plain string, not an ENUM, deliberately — no grading
 * scale is fixed yet, and this codebase has already been bitten once by an
 * ENUM that couldn't hold the values the code used (admin_users.role).
 *
 * Additive/guarded, same pattern as the other post-launch migrations here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'condition_grade')) {
                $table->string('condition_grade', 10)->nullable()->after('in_stock');
                $table->decimal('tread_depth_mm', 4, 1)->nullable()->after('condition_grade');
                $table->string('dot_code', 20)->nullable()->after('tread_depth_mm');
                $table->date('inspection_date')->nullable()->after('dot_code');
                $table->json('inspection_photos')->nullable()->after('inspection_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'condition_grade')) {
                $table->dropColumn([
                    'condition_grade',
                    'tread_depth_mm',
                    'dot_code',
                    'inspection_date',
                    'inspection_photos',
                ]);
            }
        });
    }
};
