<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 20)->default('website')->after('ref')->index();
            $table->string('ebay_order_id', 50)->nullable()->unique()->after('source');
            $table->string('ebay_order_status', 30)->nullable()->after('ebay_order_id');
            $table->string('ebay_payment_status', 30)->nullable()->after('ebay_order_status');
            $table->string('ebay_fulfillment_status', 30)->nullable()->after('ebay_payment_status');
            $table->string('ebay_buyer_username', 100)->nullable()->after('ebay_fulfillment_status');
            $table->timestamp('ebay_last_synced_at')->nullable()->after('ebay_buyer_username');
            $table->json('ebay_raw_summary')->nullable()->after('ebay_last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropUnique(['ebay_order_id']);
            $table->dropColumn([
                'source',
                'ebay_order_id',
                'ebay_order_status',
                'ebay_payment_status',
                'ebay_fulfillment_status',
                'ebay_buyer_username',
                'ebay_last_synced_at',
                'ebay_raw_summary',
            ]);
        });
    }
};
