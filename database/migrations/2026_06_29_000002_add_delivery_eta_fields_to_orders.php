<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery ETA / progress (Traccar live tracking).
 *
 *   dest_lat / dest_lon  — destination coordinates (geocoded once from the
 *                          delivery address via OSM Nominatim, then cached here).
 *   route_total_km       — straight-line(×road factor) distance snapshot taken
 *                          the first time the shipped order is viewed, used as the
 *                          baseline for the delivery progress bar.
 *
 * Additive + guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'dest_lat')) {
                $table->decimal('dest_lat', 10, 7)->nullable()->after('tracking_device_id');
            }
            if (! Schema::hasColumn('orders', 'dest_lon')) {
                $table->decimal('dest_lon', 10, 7)->nullable()->after('dest_lat');
            }
            if (! Schema::hasColumn('orders', 'route_total_km')) {
                $table->decimal('route_total_km', 8, 2)->nullable()->after('dest_lon');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            foreach (['dest_lat', 'dest_lon', 'route_total_km'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
