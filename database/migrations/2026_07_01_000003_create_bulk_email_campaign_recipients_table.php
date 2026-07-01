<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-recipient send record for a bulk_email_campaigns row. Snapshotted at
 * dispatch time so the campaign's send list is fixed even if contacts change
 * afterwards, and so the send job can skip rows it already processed
 * (safe to retry a failed queue job without double-emailing).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bulk_email_campaign_recipients')) {
            Schema::create('bulk_email_campaign_recipients', function (Blueprint $table) {
                $table->id();

                $table->foreignId('campaign_id')
                    ->constrained('bulk_email_campaigns')
                    ->cascadeOnDelete();

                $table->foreignId('contact_id')
                    ->constrained('marketing_contacts')
                    ->cascadeOnDelete();

                $table->string('email', 255);

                // pending | sent | failed
                $table->string('status', 20)->default('pending');
                $table->text('error')->nullable();
                $table->timestamp('sent_at')->nullable();

                $table->timestamps();

                $table->unique(['campaign_id', 'contact_id']);
                $table->index(['campaign_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_email_campaign_recipients');
    }
};
