<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traccar live GPS tracking — link an order to a tracked device.
 *
 * Distinct from the existing freight columns (carrier / tracking_number /
 * container_number, which cover DHL + sea-freight). `tracking_device_id` holds
 * the Traccar device id so a customer can follow the vehicle delivering their
 * order from the portal. Additive + guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'tracking_device_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('tracking_device_id', 64)->nullable()->after('tracking_status');
                $table->index('tracking_device_id', 'orders_tracking_device_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'tracking_device_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_tracking_device_idx');
                $table->dropColumn('tracking_device_id');
            });
        }
    }
};
