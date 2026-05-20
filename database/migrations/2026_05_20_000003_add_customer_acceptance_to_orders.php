<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // pending | accepted | rejected
            $table->string('customer_acceptance_status', 20)->default('pending')->after('financials_revision_changes');
            $table->timestamp('customer_accepted_at')->nullable()->after('customer_acceptance_status');
            $table->string('customer_accepted_ip', 45)->nullable()->after('customer_accepted_at');
            $table->text('customer_accepted_user_agent')->nullable()->after('customer_accepted_ip');
            $table->text('customer_acceptance_note')->nullable()->after('customer_accepted_user_agent');
            // Signed acceptance token for non-account customers (emailed link)
            $table->string('acceptance_token', 64)->nullable()->unique()->after('customer_acceptance_note');
            $table->timestamp('acceptance_token_expires_at')->nullable()->after('acceptance_token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'customer_acceptance_status',
                'customer_accepted_at',
                'customer_accepted_ip',
                'customer_accepted_user_agent',
                'customer_acceptance_note',
                'acceptance_token',
                'acceptance_token_expires_at',
            ]);
        });
    }
};
