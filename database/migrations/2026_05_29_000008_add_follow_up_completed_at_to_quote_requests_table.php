<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            // Tracks when a follow-up was last completed, without clearing history.
            // When admin completes: follow_up_at = null, follow_up_completed_at = now().
            // When admin reschedules: follow_up_at = new date (follow_up_completed_at untouched).
            $table->timestamp('follow_up_completed_at')->nullable()->after('follow_up_at');
            $table->unsignedBigInteger('follow_up_completed_by')->nullable()->after('follow_up_completed_at');
            $table->foreign('follow_up_completed_by')->references('id')->on('admin_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropForeign(['follow_up_completed_by']);
            $table->dropColumn(['follow_up_completed_at', 'follow_up_completed_by']);
        });
    }
};
