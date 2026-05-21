<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('deposit_requested_email_sent_at')->nullable()->after('shipment_release_note');
            $table->timestamp('deposit_paid_email_sent_at')->nullable()->after('deposit_requested_email_sent_at');
            $table->timestamp('balance_due_email_sent_at')->nullable()->after('deposit_paid_email_sent_at');
            $table->timestamp('balance_paid_email_sent_at')->nullable()->after('balance_due_email_sent_at');
            $table->timestamp('shipment_released_email_sent_at')->nullable()->after('balance_paid_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'deposit_requested_email_sent_at',
                'deposit_paid_email_sent_at',
                'balance_due_email_sent_at',
                'balance_paid_email_sent_at',
                'shipment_released_email_sent_at',
            ]);
        });
    }
};
