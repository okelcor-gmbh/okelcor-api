<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Block-based campaign design, so the marketing team stops writing HTML.
 *
 * `campaign_templates` holds designs the team saves and reuses. The built-in
 * starting points are code, not rows (App\Support\CampaignStarterTemplates) —
 * they can't be deleted by accident and improve with a deploy.
 *
 * `bulk_email_campaigns` gains `blocks` + `theme`. The rendered HTML still lands
 * in the existing `body_html` column at creation time, so the queue, the resume
 * logic, per-recipient token substitution and the send job are all completely
 * unchanged — this is a new way to *author* a campaign, not a new way to send
 * one. Keeping the blocks alongside the HTML is what lets a sent campaign be
 * reopened in the editor or duplicated later.
 *
 * Both additions are guarded, so a partially-applied deploy re-runs cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_templates')) {
            Schema::create('campaign_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('description', 500)->nullable();
                $table->json('blocks');
                $table->json('theme')->nullable();
                // Nullable + nullOnDelete: a template outlives the admin who
                // saved it. Losing a shared design because someone left would
                // be worse than an orphan row.
                $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                $table->index('name');
            });
        }

        Schema::table('bulk_email_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('bulk_email_campaigns', 'blocks')) {
                $table->json('blocks')->nullable()->after('body_html');
            }
            if (! Schema::hasColumn('bulk_email_campaigns', 'theme')) {
                $table->json('theme')->nullable()->after('blocks');
            }
            // Plain-text alternative, generated from the blocks. A bulk
            // HTML-only message is markedly more likely to be treated as spam,
            // and some recipients read text only. Null for pasted-HTML
            // campaigns, where there's nothing to derive it from.
            if (! Schema::hasColumn('bulk_email_campaigns', 'body_text')) {
                $table->longText('body_text')->nullable()->after('theme');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_templates');

        Schema::table('bulk_email_campaigns', function (Blueprint $table) {
            foreach (['blocks', 'theme', 'body_text'] as $column) {
                if (Schema::hasColumn('bulk_email_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
