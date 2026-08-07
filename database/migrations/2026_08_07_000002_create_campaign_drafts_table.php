<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work-in-progress campaign editor state, so leaving the Mail Campaign tab
 * does not throw away everything typed so far.
 *
 * Reported by a marketer: opening the Media Library mid-compose and coming
 * back lost the whole campaign. The cause was that nothing persisted work in
 * progress at all — `POST /admin/bulk-emails` creates a campaign AND
 * immediately dispatches it, so it is a send button, not a save. Until a
 * campaign is sent it existed only in browser memory.
 *
 * A SEPARATE TABLE rather than nullable columns on `bulk_email_campaigns`,
 * for three reasons:
 *
 *  1. A draft is legitimately incomplete and often invalid mid-edit — no
 *     subject yet, a Button block with no URL, no recipient filter. Those
 *     rows would fail `bulk_email_campaigns`' NOT NULL `subject`/`body_html`,
 *     and making those nullable would mean a MySQL-only ALTER on a table
 *     holding real send history.
 *  2. Half-finished editor state in the campaigns table would pollute the
 *     campaign list, the `status` index, and every count of "campaigns".
 *  3. Drafts are personal scratch with a different lifecycle: they are
 *     disposable, pruned, and deleted once the campaign actually sends.
 *     `campaign_templates` (Session 72) is the opposite — a deliberate,
 *     shared, reusable design.
 *
 * Nothing existing is read, altered or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_drafts')) {
            return;
        }

        Schema::create('campaign_drafts', function (Blueprint $table) {
            $table->id();

            // A draft is personal until it is sent. Deleted with the account:
            // unlike a saved template, nobody else's work depends on it.
            $table->foreignId('admin_user_id')
                ->constrained('admin_users')
                ->cascadeOnDelete();

            // EVERY content column is nullable. Autosave fires while the
            // marketer is still typing, and a save that refuses incomplete
            // work is a save that does not run when it is most needed.
            $table->string('subject', 255)->nullable();
            $table->json('blocks')->nullable();
            $table->json('theme')->nullable();
            $table->longText('body_html')->nullable();  // pasted-HTML authoring path
            $table->json('filters')->nullable();

            // Optional label so a marketer juggling two campaigns can tell
            // them apart in the "restore" list. Falls back to the subject.
            $table->string('name', 150)->nullable();

            $table->timestamps();

            // "The most recent draft for this admin" is the query the editor
            // makes on load, every time.
            $table->index(['admin_user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_drafts');
    }
};
