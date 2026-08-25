<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-recipient campaign engagement tracking, for the boss's marketer
 * scoreboard: opened_at/open_count fed by a tracking pixel, clicked_at/
 * click_count fed by signed link redirects ("completion"). tracking_token
 * is the unguessable per-recipient key both endpoints resolve; rows from
 * campaigns sent before this feature simply have none and score as
 * untracked, never as zero engagement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_email_campaign_recipients', function (Blueprint $table) {
            if (! Schema::hasColumn('bulk_email_campaign_recipients', 'tracking_token')) {
                $table->string('tracking_token', 64)->nullable()->unique()->after('status');
                $table->timestamp('opened_at')->nullable()->after('sent_at');
                $table->unsignedInteger('open_count')->default(0)->after('opened_at');
                $table->timestamp('clicked_at')->nullable()->after('open_count');
                $table->unsignedInteger('click_count')->default(0)->after('clicked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bulk_email_campaign_recipients', function (Blueprint $table) {
            foreach (['tracking_token', 'opened_at', 'open_count', 'clicked_at', 'click_count'] as $col) {
                if (Schema::hasColumn('bulk_email_campaign_recipients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
