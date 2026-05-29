<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            // Set when a guest quote submission matches an existing customer by email
            $table->unsignedBigInteger('possible_customer_id')->nullable()->after('customer_id');
            $table->foreign('possible_customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropForeign(['possible_customer_id']);
            $table->dropColumn('possible_customer_id');
        });
    }
};
