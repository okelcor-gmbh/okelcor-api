<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            // pending | accepted | rejected
            $table->string('customer_acceptance_status', 20)->default('pending')->after('admin_notes');
            $table->timestamp('customer_accepted_at')->nullable()->after('customer_acceptance_status');
            $table->string('customer_accepted_ip', 45)->nullable()->after('customer_accepted_at');
            $table->text('customer_accepted_user_agent')->nullable()->after('customer_accepted_ip');
            $table->text('customer_acceptance_note')->nullable()->after('customer_accepted_user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropColumn([
                'customer_acceptance_status',
                'customer_accepted_at',
                'customer_accepted_ip',
                'customer_accepted_user_agent',
                'customer_acceptance_note',
            ]);
        });
    }
};
