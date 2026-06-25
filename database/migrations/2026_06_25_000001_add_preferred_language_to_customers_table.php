<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a per-customer preferred language so transactional emails and trade
 * documents can be rendered in the buyer's language (en/de/fr/es) instead of
 * English-only. Additive and safe: existing customers default to English, so
 * nothing changes until a language is explicitly chosen.
 *
 * Once a customer has this set, the HasLocalePreference contract on the Customer
 * model makes Laravel automatically localize any mail/notification sent to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'preferred_language')) {
                $table->enum('preferred_language', ['en', 'de', 'fr', 'es'])
                    ->default('en')
                    ->after('country');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'preferred_language')) {
                $table->dropColumn('preferred_language');
            }
        });
    }
};
