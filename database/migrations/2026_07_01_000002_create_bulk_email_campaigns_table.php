<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk email campaigns sent to the marketing_contacts list. One row per
 * send; recipient filters + progress counters live on the row so the admin
 * UI can poll a single endpoint for status.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bulk_email_campaigns')) {
            Schema::create('bulk_email_campaigns', function (Blueprint $table) {
                $table->id();

                $table->string('subject', 255);
                $table->longText('body_html');
                $table->json('filters')->nullable(); // { company, country, status, search }

                $table->unsignedInteger('total_recipients')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);

                // draft | queued | sending | completed | failed
                $table->string('status', 20)->default('draft');

                $table->foreignId('created_by')->constrained('admin_users');
                $table->timestamp('completed_at')->nullable();

                $table->timestamps();

                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_email_campaigns');
    }
};
