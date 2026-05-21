<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Stages: pending_proforma → deposit_requested → deposit_paid
            //         → balance_due → balance_paid → shipment_released
            $table->string('payment_stage', 30)->default('pending_proforma')->after('payment_session_id');
            $table->decimal('deposit_percent', 5, 2)->default(50)->after('payment_stage');
            $table->decimal('deposit_amount', 12, 2)->nullable()->after('deposit_percent');
            $table->timestamp('deposit_paid_at')->nullable()->after('deposit_amount');
            $table->unsignedBigInteger('deposit_confirmed_by')->nullable()->after('deposit_paid_at');
            $table->decimal('balance_amount', 12, 2)->nullable()->after('deposit_confirmed_by');
            $table->timestamp('balance_paid_at')->nullable()->after('balance_amount');
            $table->unsignedBigInteger('balance_confirmed_by')->nullable()->after('balance_paid_at');
            $table->timestamp('shipment_released_at')->nullable()->after('balance_confirmed_by');
            $table->unsignedBigInteger('shipment_released_by')->nullable()->after('shipment_released_at');
            $table->text('shipment_release_note')->nullable()->after('shipment_released_by');

            $table->foreign('deposit_confirmed_by')->references('id')->on('admin_users')->nullOnDelete();
            $table->foreign('balance_confirmed_by')->references('id')->on('admin_users')->nullOnDelete();
            $table->foreign('shipment_released_by')->references('id')->on('admin_users')->nullOnDelete();
        });

        // Existing fully-paid orders are at balance_paid stage (full payment collected upfront)
        DB::statement("
            UPDATE orders
            SET payment_stage = 'balance_paid'
            WHERE payment_status = 'paid'
              AND payment_stage = 'pending_proforma'
        ");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['deposit_confirmed_by']);
            $table->dropForeign(['balance_confirmed_by']);
            $table->dropForeign(['shipment_released_by']);
            $table->dropColumn([
                'payment_stage',
                'deposit_percent',
                'deposit_amount',
                'deposit_paid_at',
                'deposit_confirmed_by',
                'balance_amount',
                'balance_paid_at',
                'balance_confirmed_by',
                'shipment_released_at',
                'shipment_released_by',
                'shipment_release_note',
            ]);
        });
    }
};
