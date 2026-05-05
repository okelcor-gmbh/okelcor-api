<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('promo_code', 50)->nullable()->after('is_reverse_charge');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('promo_code');
            $table->string('discount_label', 255)->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['promo_code', 'discount_amount', 'discount_label']);
        });
    }
};
