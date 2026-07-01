<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing contact list — imported from external contact exports (Wix CSV
 * format) for admin bulk-email campaigns. Distinct from `customers` (no
 * portal login/account) and from `contact_messages` (contact-form inbox).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_contacts')) {
            Schema::create('marketing_contacts', function (Blueprint $table) {
                $table->id();

                $table->string('email')->unique();
                $table->string('first_name', 100)->nullable();
                $table->string('last_name', 100)->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('company', 150)->nullable();
                $table->string('country', 100)->nullable();
                $table->string('vat_id', 50)->nullable();
                $table->string('labels', 255)->nullable();
                $table->string('source', 100)->nullable();

                // subscribed | unsubscribed | unknown (unknown = no explicit opt-in on record)
                $table->string('status', 20)->default('unknown');
                $table->string('unsubscribe_token', 64)->unique()->nullable();

                $table->timestamp('imported_at')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('company');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_contacts');
    }
};
