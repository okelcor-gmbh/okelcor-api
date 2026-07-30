<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one marketing contact belong to several markets at once.
 *
 * Until now `marketing_contacts.market` was a single string column, so a
 * contact was in exactly one market — the marketing team could not have the
 * same address in, say, both `test` and `germany` without duplicating the row
 * (impossible: `email` is UNIQUE). Membership moves into its own table so a
 * contact can be targeted by more than one market's campaign.
 *
 * `marketing_contacts.market` is deliberately KEPT, not dropped, and is now
 * the contact's *primary* market — the one shown in single-value UI and in
 * every API response that already returned `market`. It is maintained as a
 * mirror of this table (see MarketingContact::refreshPrimaryMarket): it always
 * holds one of the contact's actual memberships, or null when it has none.
 * Nothing that reads the old column breaks, and CLAUDE.md's "do not change
 * database column names" rule is respected.
 *
 * Backfills one membership row per existing contact from its current market,
 * so behaviour is identical the moment this runs — every contact keeps exactly
 * the market it already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_contact_markets')) {
            Schema::create('marketing_contact_markets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
                $table->string('market', 50);
                $table->timestamps();

                // One membership row per (contact, market) — makes an "add to
                // market" operation safely repeatable via insertOrIgnore.
                $table->unique(['contact_id', 'market']);
                $table->index('market');
            });
        }

        // Backfill: every existing contact keeps exactly its current market.
        // insertOrIgnore so a re-run (or a partially-applied migration) can
        // never duplicate a membership or fail on the unique index.
        DB::table('marketing_contacts')
            ->whereNotNull('market')
            ->where('market', '!=', '')
            ->orderBy('id')
            ->select('id', 'market')
            ->chunk(500, function ($contacts) {
                $now  = now();
                $rows = [];

                foreach ($contacts as $contact) {
                    $rows[] = [
                        'contact_id' => $contact->id,
                        'market'     => $contact->market,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('marketing_contact_markets')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_contact_markets');
    }
};
