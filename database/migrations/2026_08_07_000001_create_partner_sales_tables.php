<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner Sales Log — the four tables behind the Okelcor Partner web app.
 *
 * Partners in other markets (Ghana first) currently report what they sold on
 * paper. This is the digital intake for that mandated process; the deliverable
 * at the Okelcor end is a clean, exportable set of numbers for the books.
 *
 * Every table here is NEW. Nothing existing is read, altered or backfilled, so
 * this migration cannot affect any live row — the only deploy risk is the code
 * landing before the tables exist, which the endpoints are not expected to
 * survive (unlike Sessions 71/72, there is no previous behaviour to fall back
 * to: without these tables the feature simply does not exist yet).
 *
 * Two schema decisions worth recording:
 *
 *  1. `partner_users.role`, `partner_sales.tyre_type`, `status` and `source`
 *     are plain STRING columns, deliberately not ENUMs. `admin_users.role` is
 *     an ENUM missing four documented roles and currently cannot store them at
 *     all (Known Gaps, High), and `security_events.type` needed a whole
 *     MySQL-only migration to widen. Allowed values are enforced in
 *     FormRequests, where adding one costs nothing.
 *
 *  2. `partner_sales` is soft-deleted. A sale that has already been exported
 *     into the books must not be able to vanish from the audit trail because
 *     a partner tapped delete on their phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Partner organisations ─────────────────────────────────────────
        // A partner is an organisation, not a person: the likely shape in
        // these markets is a distributor with a shop and staff. Sales are
        // owned here, and `entered_by_user_id` on the sale records who typed
        // it. Collapsing this to one-user-per-partner later is trivial;
        // splitting a single-user model later would mean rewriting every row
        // and every report.
        if (! Schema::hasTable('partner_organisations')) {
            Schema::create('partner_organisations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);

                // Market is DERIVED from country (see PartnerOrganisation::market)
                // rather than stored. `customers.market_region` and
                // `marketing_contacts.market` are already two separate market
                // vocabularies in this codebase; a third stored one would be
                // a third thing to keep in sync.
                $table->string('country', 100);
                $table->char('country_code', 2)->nullable();

                // Default only — the currency that matters is the one recorded
                // per sale, as entered.
                $table->char('default_currency', 3)->default('USD');

                $table->string('contact_email', 255)->nullable();
                $table->string('contact_phone', 30)->nullable();
                $table->string('status', 20)->default('active'); // active | suspended
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('country');
                $table->index('status');
            });
        }

        // ── Partner users ─────────────────────────────────────────────────
        if (! Schema::hasTable('partner_users')) {
            Schema::create('partner_users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('partner_org_id')
                    ->constrained('partner_organisations')
                    ->cascadeOnDelete();

                $table->string('name', 150);

                // The login identifier. Stored normalised to E.164-ish digits
                // (see PartnerUser::normalisePhone) so "+233 24 123 4567" and
                // "0241234567" cannot become two accounts.
                $table->string('phone', 30)->unique();

                // Bcrypt hash of a 6+ digit PIN. Never the PIN itself.
                $table->string('pin_hash');

                $table->string('role', 30)->default('staff'); // owner | staff
                $table->boolean('is_active')->default(true);

                // Partners are created by Okelcor admin with a starting PIN,
                // so the first login must force a change — an admin-chosen PIN
                // is known to at least one other person by construction.
                $table->boolean('must_change_pin')->default(true);
                $table->timestamp('pin_changed_at')->nullable();

                // Lockout state. Shared devices and a 6-digit secret make
                // online brute force the realistic attack, so failures are
                // counted per account, not only per IP.
                $table->unsignedSmallInteger('failed_pin_attempts')->default(0);
                $table->timestamp('locked_until')->nullable();
                $table->timestamp('last_login_at')->nullable();

                $table->timestamps();

                $table->index(['partner_org_id', 'is_active']);
            });
        }

        // ── Partner sales ─────────────────────────────────────────────────
        if (! Schema::hasTable('partner_sales')) {
            Schema::create('partner_sales', function (Blueprint $table) {
                $table->id();

                $table->foreignId('partner_org_id')
                    ->constrained('partner_organisations')
                    ->cascadeOnDelete();

                // Records WHO typed it, for the first time a figure looks
                // wrong. nullOnDelete because a sale has to outlive the person
                // who left the partner — same rule as campaign_templates.created_by.
                $table->foreignId('entered_by_user_id')
                    ->nullable()
                    ->constrained('partner_users')
                    ->nullOnDelete();

                // The offline dedupe key, minted on the device before the entry
                // is ever sent. Scoped to the ORGANISATION, not the user: on a
                // shared device a re-authenticated colleague re-pushing the
                // queue must not be able to create a second copy of the sale.
                $table->string('client_generated_id', 64);

                // Monotonic edit counter, optional for the client to send.
                // Guards against an out-of-order offline delivery (a retry of
                // v1 landing after v2 already synced) silently reverting a
                // correction. Clock-free on purpose — these are cheap shared
                // Android handsets and their clocks drift.
                $table->unsignedInteger('client_revision')->default(1);

                // Partner-declared date of sale. NOT a timestamp — backdating
                // is expected, there is a paper backlog to enter.
                $table->date('sold_at');

                $table->string('size', 50);
                $table->string('brand', 100)->nullable();
                $table->string('tyre_type', 20)->nullable(); // pcr | tbr | otr | used

                // Set only when the free-text size/brand matches a catalogue
                // row. Nullable forever — partners sell things we do not list.
                $table->foreignId('product_id')
                    ->nullable()
                    ->constrained('products')
                    ->nullOnDelete();

                $table->unsignedInteger('quantity');
                $table->decimal('unit_price', 12, 2);

                // Computed server-side as quantity × unit_price, never accepted
                // from the client, so the stored total cannot disagree with its
                // own line. Stored rather than derived because the books export
                // and every summary SUM over it.
                $table->decimal('total_amount', 14, 2);

                // Recorded as entered, never converted. There is no FX source
                // covering NGN/GHS/KES/AED (Frankfurter is ECB-sourced and does
                // not publish them), so conversion is a finance decision at the
                // Okelcor end against a dated rate — the export carries amount,
                // currency and date for exactly that.
                $table->char('currency', 3);

                $table->string('customer_name', 150)->nullable();
                $table->text('notes')->nullable();

                $table->string('source', 10)->default('app');      // app | admin
                $table->string('status', 20)->default('submitted'); // submitted | verified | disputed

                $table->foreignId('verified_by')
                    ->nullable()
                    ->constrained('admin_users')
                    ->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->text('review_note')->nullable();

                $table->timestamps();
                $table->softDeletes();

                // The idempotency key. One sale per (organisation, device id).
                $table->unique(['partner_org_id', 'client_generated_id']);

                $table->index(['partner_org_id', 'sold_at']);
                $table->index(['status', 'sold_at']);
                $table->index('sold_at');
            });
        }

        // ── Audit trail ───────────────────────────────────────────────────
        // Append-only: no updated_at, nothing in the application ever updates
        // a row here. Records the ACTOR separately from the sale's
        // entered_by_user_id, because a colleague in the same organisation can
        // edit an entry inside the window and "who changed what" is the whole
        // point of keeping this.
        if (! Schema::hasTable('partner_sale_audits')) {
            Schema::create('partner_sale_audits', function (Blueprint $table) {
                $table->id();

                $table->foreignId('partner_sale_id')
                    ->constrained('partner_sales')
                    ->cascadeOnDelete();

                $table->string('action', 30); // created | updated | deleted | verified | disputed | restored

                $table->string('actor_type', 20)->nullable(); // partner_user | admin_user | system
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_label', 150)->nullable(); // denormalised: survives the actor being deleted

                $table->json('changes')->nullable();
                $table->string('ip_address', 45)->nullable();

                $table->timestamp('created_at')->useCurrent();

                $table->index(['partner_sale_id', 'created_at']);
                $table->index(['action', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_sale_audits');
        Schema::dropIfExists('partner_sales');
        Schema::dropIfExists('partner_users');
        Schema::dropIfExists('partner_organisations');
    }
};
