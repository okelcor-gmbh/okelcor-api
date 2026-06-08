<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-8 — Customer verification records.
 *
 * One row per verifiable attribute (company registration, VAT, website,
 * import license, business address, …). Admin reviews each and marks it
 * verified / rejected. Feeds CustomerHealthService scoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_verifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->enum('type', [
                'company_registration',
                'vat_number',
                'website',
                'import_license',
                'business_address',
                'other',
            ]);

            $table->text('value')->nullable();

            $table->enum('status', [
                'not_submitted', 'pending_review', 'verified', 'rejected',
            ])->default('pending_review');

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('reviewed_by')->references('id')->on('admin_users')->nullOnDelete();

            $table->index('customer_id');
            $table->index(['customer_id', 'type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_verifications');
    }
};
