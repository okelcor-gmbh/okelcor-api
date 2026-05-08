<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eu_declarations', function (Blueprint $table) {
            $table->string('street', 255)->nullable()->after('customer_address');
            $table->string('city', 100)->nullable()->after('street');
            $table->string('postal_code', 20)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('eu_declarations', function (Blueprint $table) {
            $table->dropColumn(['street', 'city', 'postal_code']);
        });
    }
};
