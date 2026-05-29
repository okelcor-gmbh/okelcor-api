<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('data_quality_score')->nullable()->after('approved_for_documents');
            $table->json('data_quality_flags')->nullable()->after('data_quality_score');
            $table->string('normalized_email', 255)->nullable()->after('data_quality_flags');
            $table->string('normalized_company_name', 255)->nullable()->after('normalized_email');
            $table->string('duplicate_group_id', 36)->nullable()->after('normalized_company_name');
            $table->unsignedBigInteger('possible_duplicate_of')->nullable()->after('duplicate_group_id');
            $table->enum('data_review_status', ['clean', 'needs_review', 'duplicate_suspected', 'merged', 'ignored'])
                  ->default('needs_review')
                  ->after('possible_duplicate_of');

            $table->foreign('possible_duplicate_of')->references('id')->on('customers')->nullOnDelete();
            $table->index('normalized_email', 'customers_normalized_email_idx');
            $table->index('data_review_status', 'customers_data_review_status_idx');
        });

        // Backfill normalized_email for all existing customers (safe SQL, no PHP logic needed)
        DB::statement("UPDATE customers SET normalized_email = LOWER(TRIM(email)) WHERE email IS NOT NULL");

        // Backfill normalized_company_name: lowercase + trim + strip common punctuation
        // Suffix removal requires PHP — the Artisan command `customers:recalculate-data-quality --all`
        // handles full normalization and scoring post-deploy.
        DB::statement("
            UPDATE customers
            SET normalized_company_name = LOWER(TRIM(REPLACE(REPLACE(REPLACE(
                REPLACE(REPLACE(company_name, '.', ' '), ',', ' '), ';', ' '), '\"', ' '), '''', ' '
            )))
            WHERE company_name IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['possible_duplicate_of']);
            $table->dropIndex('customers_normalized_email_idx');
            $table->dropIndex('customers_data_review_status_idx');
            $table->dropColumn([
                'data_quality_score',
                'data_quality_flags',
                'normalized_email',
                'normalized_company_name',
                'duplicate_group_id',
                'possible_duplicate_of',
                'data_review_status',
            ]);
        });
    }
};
