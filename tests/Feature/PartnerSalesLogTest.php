<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\PartnerOrganisation;
use App\Models\PartnerSale;
use App\Models\PartnerUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Partner Sales Log — partner intake, the idempotency contract, and the
 * Okelcor-side review + books export.
 *
 * Does NOT use RefreshDatabase: the full migration set includes a MySQL-only
 * legacy migration (`ALTER TABLE ... MODIFY COLUMN`) that sqlite cannot run.
 * Creates only the tables these tests touch — the same pattern as
 * BulkEmailCampaignTest and MediaLibraryTest, so this runs locally and in CI
 * rather than being skipped behind the MySQL gate.
 */
class PartnerSalesLogTest extends TestCase
{
    private PartnerOrganisation $org;
    private PartnerUser $partnerUser;
    private string $partnerToken;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'partner_sale_audits',
            'partner_sales',
            'partner_users',
            'partner_organisations',
            'admin_security_events',
            'personal_access_tokens',
            'products',
            'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60);
            $table->string('severity', 20)->default('info');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_email', 255)->nullable();
            $table->string('admin_role', 60)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('description')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('brand', 100);
            $table->string('width', 10)->nullable();
            $table->string('height', 10)->nullable();
            $table->string('rim', 10)->nullable();
            $table->timestamps();
            $table->softDeletes(); // Product uses SoftDeletes
        });

        Schema::create('partner_organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('country', 100);
            $table->string('country_code', 2)->nullable();
            $table->string('default_currency', 3)->default('USD');
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_org_id');
            $table->string('name', 150);
            $table->string('phone', 30)->unique();
            $table->string('pin_hash');
            $table->string('role', 30)->default('staff');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_pin')->default(true);
            $table->timestamp('pin_changed_at')->nullable();
            $table->unsignedSmallInteger('failed_pin_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_org_id');
            $table->unsignedBigInteger('entered_by_user_id')->nullable();
            $table->string('client_generated_id', 64);
            $table->unsignedInteger('client_revision')->default(1);
            $table->date('sold_at');
            $table->string('size', 50);
            $table->string('brand', 100)->nullable();
            $table->string('tyre_type', 20)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_amount', 14, 2);
            $table->string('currency', 3);
            $table->string('customer_name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 10)->default('app');
            $table->string('status', 20)->default('submitted');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['partner_org_id', 'client_generated_id']);
        });

        Schema::create('partner_sale_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_sale_id');
            $table->string('action', 30);
            $table->string('actor_type', 20)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 150)->nullable();
            $table->text('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->org = PartnerOrganisation::create([
            'name'             => 'Accra Tyre Distributors',
            'country'          => 'Ghana',
            'country_code'     => 'GH',
            'default_currency' => 'GHS',
        ]);

        $this->partnerUser = PartnerUser::create([
            'partner_org_id'  => $this->org->id,
            'name'            => 'Kwame Mensah',
            'phone'           => '233241234567',
            'pin_hash'        => Hash::make('849271'),
            'role'            => 'owner',
            'is_active'       => true,
            'must_change_pin' => false,
        ]);

        $this->partnerToken = $this->partnerUser->createToken('test')->plainTextToken;
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function partnerHeaders(?string $token = null): array
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->partnerToken)];
    }

    private function salePayload(array $overrides = []): array
    {
        return array_merge([
            'client_generated_id' => 'dev-uuid-0000-0001',
            'sold_at'             => now()->subDay()->toDateString(),
            'size'                => '315/70 R22.5',
            'brand'               => 'Michelin',
            'tyre_type'           => 'tbr',
            'quantity'            => 4,
            'unit_price'          => 250.00,
            'currency'            => 'GHS',
        ], $overrides);
    }

    private function adminToken(string $role = 'admin'): string
    {
        static $seq = 0;
        $seq++;

        $admin = AdminUser::create([
            'name'                    => 'Ops ' . $seq,
            'email'                   => "ops{$seq}@okelcor.com",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);

        return $admin->createToken('admin-test')->plainTextToken;
    }

    private function adminHeaders(string $role = 'admin'): array
    {
        return ['Authorization' => 'Bearer ' . $this->adminToken($role)];
    }

    // ── auth ──────────────────────────────────────────────────────────────

    public function test_partner_logs_in_with_phone_and_pin(): void
    {
        $response = $this->postJson('/api/v1/partner/auth/login', [
            'phone' => '+233 24 123 4567',
            'pin'   => '849271',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.name', 'Kwame Mensah')
            ->assertJsonPath('data.user.organisation.market', 'ghana');

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_phone_is_normalised_so_formatting_is_not_a_second_account(): void
    {
        // Same number, four ways a person might type it on a phone keypad.
        foreach (['+233241234567', '233 241 234 567', '(233)24-123-4567', '233241234567'] as $variant) {
            $this->postJson('/api/v1/partner/auth/login', ['phone' => $variant, 'pin' => '849271'])
                ->assertOk();
        }
    }

    public function test_wrong_pin_is_rejected_and_does_not_reveal_whether_the_phone_exists(): void
    {
        $known = $this->postJson('/api/v1/partner/auth/login', [
            'phone' => '233241234567', 'pin' => '000999',
        ]);

        $unknown = $this->postJson('/api/v1/partner/auth/login', [
            'phone' => '233209999999', 'pin' => '000999',
        ]);

        $known->assertStatus(401);
        $unknown->assertStatus(401);

        // Identical body and status — the endpoint cannot be used to enumerate
        // which phone numbers are registered partners.
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_account_locks_after_repeated_wrong_pins(): void
    {
        // The IP+phone throttle is deliberately disabled here so this exercises
        // the ACCOUNT lockout specifically. In production the throttle fires
        // first for a single-IP attacker; the lockout exists for the
        // distributed case, where no IP-based limit helps.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $max = (int) config('partner.pin.max_attempts', 5);

        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/v1/partner/auth/login', [
                'phone' => '233241234567', 'pin' => '000999',
            ])->assertStatus(401);
        }

        // Even the CORRECT PIN is refused while locked.
        $this->postJson('/api/v1/partner/auth/login', [
            'phone' => '233241234567', 'pin' => '849271',
        ])->assertStatus(423)->assertJsonPath('code', 'account_locked');

        $this->assertTrue($this->partnerUser->fresh()->isLocked());
    }

    public function test_login_failures_are_written_to_the_security_log(): void
    {
        $this->postJson('/api/v1/partner/auth/login', [
            'phone' => '233241234567', 'pin' => '000999',
        ])->assertStatus(401);

        $this->assertDatabaseHas('admin_security_events', [
            'type'     => 'partner_login_failed',
            'severity' => 'warning',
        ]);
    }

    public function test_a_weak_pin_is_refused_on_change(): void
    {
        foreach (['1234', '111111', '123456', '654321', '121212'] as $weak) {
            $this->postJson('/api/v1/partner/auth/change-pin', [
                'current_pin' => '849271',
                'new_pin'     => $weak,
            ], $this->partnerHeaders())
                ->assertStatus(422)
                ->assertJsonValidationErrors('new_pin');
        }

        $this->postJson('/api/v1/partner/auth/change-pin', [
            'current_pin' => '849271',
            'new_pin'     => '748392',
        ], $this->partnerHeaders())->assertOk();

        $this->assertFalse($this->partnerUser->fresh()->must_change_pin);
    }

    public function test_an_admin_set_pin_must_be_changed_before_anything_else_works(): void
    {
        // A partner created by Okelcor admin starts with a PIN that admin chose
        // and therefore knows. On shared devices that is precisely the exposure
        // the PIN exists to cover, so the gate is enforced here, not only in
        // the client.
        $fresh = PartnerUser::create([
            'partner_org_id'  => $this->org->id,
            'name'            => 'Yaw Asante',
            'phone'           => '233555000111',
            'pin_hash'        => Hash::make('482913'),
            'must_change_pin' => true,
        ]);

        $headers = ['Authorization' => 'Bearer ' . $fresh->createToken('t')->plainTextToken];

        foreach ([
            ['post', '/api/v1/partner/sales'],
            ['get', '/api/v1/partner/sales'],
            ['get', '/api/v1/partner/summary'],
            ['get', '/api/v1/partner/sizes'],
        ] as [$method, $uri]) {
            $this->json($method, $uri, $method === 'post' ? $this->salePayload() : [], $headers)
                ->assertStatus(428)
                ->assertJsonPath('code', 'pin_change_required');
        }

        // No sale slipped through the gate.
        $this->assertDatabaseCount('partner_sales', 0);

        // The endpoints needed to satisfy or exit the gate still work — nobody
        // gets trapped in a session they cannot leave.
        $this->getJson('/api/v1/partner/me', $headers)
            ->assertOk()
            ->assertJsonPath('data.must_change_pin', true);

        $this->postJson('/api/v1/partner/auth/change-pin', [
            'current_pin' => '482913',
            'new_pin'     => '617394',
        ], $headers)->assertOk();

        // And the gate lifts immediately afterwards, on the same token.
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $headers)->assertStatus(201);
    }

    public function test_the_pin_gate_cannot_be_satisfied_with_a_weak_pin(): void
    {
        $fresh = PartnerUser::create([
            'partner_org_id'  => $this->org->id,
            'name'            => 'Yaw Asante',
            'phone'           => '233555000111',
            'pin_hash'        => Hash::make('482913'),
            'must_change_pin' => true,
        ]);

        $headers = ['Authorization' => 'Bearer ' . $fresh->createToken('t')->plainTextToken];

        $this->postJson('/api/v1/partner/auth/change-pin', [
            'current_pin' => '482913',
            'new_pin'     => '123456',
        ], $headers)->assertStatus(422);

        // Still gated — a rejected change must not count as having changed it.
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $headers)->assertStatus(428);
    }

    public function test_an_admin_pin_reset_re_arms_the_gate(): void
    {
        // The setUp user has already chosen their own PIN and can log sales.
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders())
            ->assertStatus(201);

        $this->patchJson(
            "/api/v1/admin/partner-users/{$this->partnerUser->id}",
            ['pin' => '927461'],
            $this->adminHeaders(),
        )->assertOk();

        // A fresh session on the admin-set PIN is gated again, because that PIN
        // is once more known to someone else.
        $login = $this->postJson('/api/v1/partner/auth/login', [
            'phone' => '233241234567', 'pin' => '927461',
        ])->assertOk();

        $this->postJson('/api/v1/partner/sales', $this->salePayload([
            'client_generated_id' => 'dev-uuid-after-reset',
        ]), ['Authorization' => 'Bearer ' . $login->json('data.token')])
            ->assertStatus(428)
            ->assertJsonPath('code', 'pin_change_required');
    }

    public function test_a_customer_or_admin_token_cannot_reach_partner_routes(): void
    {
        $this->getJson('/api/v1/partner/me', $this->adminHeaders())->assertStatus(401);
        $this->getJson('/api/v1/partner/me')->assertStatus(401);
    }

    public function test_a_suspended_organisation_cannot_use_an_existing_token(): void
    {
        $this->org->update(['status' => 'suspended']);

        $this->getJson('/api/v1/partner/me', $this->partnerHeaders())
            ->assertStatus(403)
            ->assertJsonPath('code', 'org_suspended');
    }

    // ── the idempotency contract ──────────────────────────────────────────

    public function test_a_sale_is_created_once(): void
    {
        $response = $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());

        $response->assertStatus(201)
            ->assertJsonPath('meta.idempotency', 'created')
            ->assertJsonPath('data.quantity', 4)
            // Total is computed server-side, never sent by the client.
            ->assertJsonPath('data.total_amount', '1000.00');

        $this->assertDatabaseCount('partner_sales', 1);
    }

    public function test_replaying_the_same_entry_never_creates_a_duplicate(): void
    {
        $payload = $this->salePayload();

        $this->postJson('/api/v1/partner/sales', $payload, $this->partnerHeaders())->assertStatus(201);

        // A flaky connection retrying the identical push, five times.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/partner/sales', $payload, $this->partnerHeaders())
                ->assertOk()
                ->assertJsonPath('meta.idempotency', 'unchanged');
        }

        $this->assertDatabaseCount('partner_sales', 1);
    }

    public function test_a_reposted_edit_updates_instead_of_being_rejected(): void
    {
        // THE collision: the client reuses client_generated_id for edits, so a
        // changed payload under a known id is a legitimate correction, not a
        // replay. Rejecting it would leave the partner seeing "Sent" while
        // Okelcor held the old figure.
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders())
            ->assertStatus(201);

        $corrected = $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['quantity' => 6, 'unit_price' => 260.00]),
            $this->partnerHeaders(),
        );

        $corrected->assertOk()
            ->assertJsonPath('meta.idempotency', 'updated')
            ->assertJsonPath('data.quantity', 6)
            ->assertJsonPath('data.total_amount', '1560.00');

        $this->assertDatabaseCount('partner_sales', 1);
    }

    public function test_the_store_endpoint_never_returns_409(): void
    {
        $payload = $this->salePayload();

        $this->postJson('/api/v1/partner/sales', $payload, $this->partnerHeaders());

        foreach ([
            $payload,
            $this->salePayload(['quantity' => 9]),
            $this->salePayload(['notes' => 'changed']),
        ] as $variant) {
            $this->postJson('/api/v1/partner/sales', $variant, $this->partnerHeaders())
                ->assertStatus(200); // never 409 — a 409 makes the outbox retry forever or drop the entry
        }
    }

    public function test_a_locked_entry_is_returned_unchanged_rather_than_erroring(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());

        // Push the entry outside the edit window by ageing its server clock.
        PartnerSale::query()->update([
            'created_at' => now()->subHours((int) config('partner.edit_window_hours', 24) + 1),
        ]);

        $response = $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['quantity' => 99]),
            $this->partnerHeaders(),
        );

        $response->assertOk()->assertJsonPath('meta.idempotency', 'unchanged_locked');

        // Payload ignored, as agreed.
        $this->assertSame(4, (int) PartnerSale::first()->quantity);
    }

    public function test_a_stale_revision_cannot_revert_a_newer_correction(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(['client_revision' => 1]), $this->partnerHeaders());

        // v2 syncs.
        $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['client_revision' => 2, 'quantity' => 8]),
            $this->partnerHeaders(),
        )->assertOk()->assertJsonPath('data.quantity', 8);

        // A retry of v1, in flight while v2 was sent, lands afterwards.
        $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['client_revision' => 1, 'quantity' => 4]),
            $this->partnerHeaders(),
        )->assertOk()->assertJsonPath('meta.idempotency', 'unchanged_stale_revision');

        $this->assertSame(8, (int) PartnerSale::first()->quantity);
    }

    public function test_a_deleted_entry_is_not_resurrected_by_a_late_push(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());
        $sale = PartnerSale::first();

        $this->deleteJson("/api/v1/partner/sales/{$sale->id}", [], $this->partnerHeaders())->assertOk();

        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders())
            ->assertOk()
            ->assertJsonPath('meta.idempotency', 'unchanged_deleted');

        $this->assertDatabaseCount('partner_sales', 1);
        $this->assertNotNull(PartnerSale::withTrashed()->first()->deleted_at);
    }

    public function test_two_partners_can_use_the_same_client_id_without_colliding(): void
    {
        $otherOrg = PartnerOrganisation::create([
            'name' => 'Lagos Tyres', 'country' => 'Nigeria', 'default_currency' => 'NGN',
        ]);
        $otherUser = PartnerUser::create([
            'partner_org_id' => $otherOrg->id,
            'name'           => 'Chidi Okonkwo',
            'phone'          => '2348012345678',
            'pin_hash'       => Hash::make('583920'),
            'must_change_pin' => false,
        ]);
        $otherToken = $otherUser->createToken('test')->plainTextToken;

        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders())
            ->assertStatus(201);

        // Same client_generated_id, different organisation — uniqueness is
        // scoped to the org, so this is a distinct sale, not a collision.
        $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['currency' => 'NGN']),
            $this->partnerHeaders($otherToken),
        )->assertStatus(201);

        $this->assertDatabaseCount('partner_sales', 2);
    }

    // ── edit window and ownership ─────────────────────────────────────────

    public function test_another_partners_entry_returns_404_not_403(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());
        $sale = PartnerSale::first();

        $otherOrg = PartnerOrganisation::create([
            'name' => 'Lagos Tyres', 'country' => 'Nigeria', 'default_currency' => 'NGN',
        ]);
        $otherUser = PartnerUser::create([
            'partner_org_id'  => $otherOrg->id,
            'name'            => 'Chidi Okonkwo',
            'phone'           => '2348012345678',
            'pin_hash'        => Hash::make('583920'),
            'must_change_pin' => false,
        ]);
        $otherToken = $otherUser->createToken('test')->plainTextToken;

        // 404, not 403 — a 403 would confirm the id exists, letting one partner
        // probe for another's entries.
        $this->patchJson("/api/v1/partner/sales/{$sale->id}", ['quantity' => 1], $this->partnerHeaders($otherToken))
            ->assertStatus(404);

        $this->deleteJson("/api/v1/partner/sales/{$sale->id}", [], $this->partnerHeaders($otherToken))
            ->assertStatus(404);
    }

    public function test_the_edit_window_is_measured_from_the_server_clock_not_sold_at(): void
    {
        // Backdated a year — if the window keyed off sold_at this would be
        // locked on arrival, and a partner entering the paper backlog could
        // never correct a typo.
        $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['sold_at' => now()->subYear()->toDateString()]),
            $this->partnerHeaders(),
        )->assertStatus(201);

        $sale = PartnerSale::first();

        $this->patchJson("/api/v1/partner/sales/{$sale->id}", ['quantity' => 7], $this->partnerHeaders())
            ->assertOk()
            ->assertJsonPath('data.quantity', 7);
    }

    public function test_editing_after_the_window_closes_is_refused(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());

        PartnerSale::query()->update([
            'created_at' => now()->subHours((int) config('partner.edit_window_hours', 24) + 1),
        ]);

        $sale = PartnerSale::first();

        $this->patchJson("/api/v1/partner/sales/{$sale->id}", ['quantity' => 7], $this->partnerHeaders())
            ->assertStatus(422)
            ->assertJsonPath('code', 'edit_window_closed');

        $this->deleteJson("/api/v1/partner/sales/{$sale->id}", [], $this->partnerHeaders())
            ->assertStatus(422);
    }

    public function test_a_patch_recomputes_the_total_even_when_only_one_side_changes(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());
        $sale = PartnerSale::first();

        $this->patchJson("/api/v1/partner/sales/{$sale->id}", ['quantity' => 10], $this->partnerHeaders())
            ->assertOk()
            ->assertJsonPath('data.total_amount', '2500.00');
    }

    public function test_every_change_is_written_to_the_audit_trail(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());
        $sale = PartnerSale::first();

        $this->patchJson("/api/v1/partner/sales/{$sale->id}", ['quantity' => 9], $this->partnerHeaders())->assertOk();

        $this->assertDatabaseHas('partner_sale_audits', ['partner_sale_id' => $sale->id, 'action' => 'created']);
        $this->assertDatabaseHas('partner_sale_audits', ['partner_sale_id' => $sale->id, 'action' => 'updated']);

        $updated = \App\Models\PartnerSaleAudit::where('action', 'updated')->first();

        $this->assertSame('Kwame Mensah', $updated->actor_label);
        $this->assertSame('4', $updated->changes['quantity']['from']);
        $this->assertSame('9', $updated->changes['quantity']['to']);
    }

    // ── validation ────────────────────────────────────────────────────────

    public function test_a_future_sale_date_is_refused(): void
    {
        $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['sold_at' => now()->addDay()->toDateString()]),
            $this->partnerHeaders(),
        )->assertStatus(422)->assertJsonValidationErrors('sold_at');
    }

    public function test_an_unknown_currency_is_refused_rather_than_silently_stored(): void
    {
        // A typo'd currency would sit outside every total in the books export,
        // and nothing here converts, so nothing else would ever catch it.
        $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['currency' => 'NGM']),
            $this->partnerHeaders(),
        )->assertStatus(422)->assertJsonValidationErrors('currency');
    }

    public function test_the_client_cannot_dictate_the_total(): void
    {
        $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['total_amount' => '1.00']),
            $this->partnerHeaders(),
        )->assertStatus(201)->assertJsonPath('data.total_amount', '1000.00');
    }

    // ── summary ───────────────────────────────────────────────────────────

    public function test_summary_totals_are_grouped_by_currency_and_never_combined(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload([
            'client_generated_id' => 'dev-uuid-a-1', 'currency' => 'GHS', 'quantity' => 2, 'unit_price' => 100,
        ]), $this->partnerHeaders());

        $this->postJson('/api/v1/partner/sales', $this->salePayload([
            'client_generated_id' => 'dev-uuid-a-2', 'currency' => 'USD', 'quantity' => 3, 'unit_price' => 50,
        ]), $this->partnerHeaders());

        $response = $this->getJson('/api/v1/partner/summary?period=month', $this->partnerHeaders());

        $response->assertOk();

        $totals = collect($response->json('data.totals'))->keyBy('currency');

        $this->assertSame('200.00', $totals['GHS']['amount']);
        $this->assertSame('150.00', $totals['USD']['amount']);
        $this->assertCount(2, $totals, 'Currencies must stay separate — there is no FX source for these markets.');
    }

    public function test_a_partner_only_sees_their_own_organisations_sales(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());

        $otherOrg = PartnerOrganisation::create([
            'name' => 'Lagos Tyres', 'country' => 'Nigeria', 'default_currency' => 'NGN',
        ]);
        $otherUser = PartnerUser::create([
            'partner_org_id'  => $otherOrg->id,
            'name'            => 'Chidi Okonkwo',
            'phone'           => '2348012345678',
            'pin_hash'        => Hash::make('583920'),
            'must_change_pin' => false,
        ]);

        $response = $this->getJson('/api/v1/partner/sales', [
            'Authorization' => 'Bearer ' . $otherUser->createToken('t')->plainTextToken,
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_colleagues_in_one_organisation_share_the_book(): void
    {
        $colleague = PartnerUser::create([
            'partner_org_id'  => $this->org->id,
            'name'            => 'Ama Boateng',
            'phone'           => '233209876543',
            'pin_hash'        => Hash::make('619473'),
            'must_change_pin' => false,
        ]);

        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());

        $response = $this->getJson('/api/v1/partner/sales', [
            'Authorization' => 'Bearer ' . $colleague->createToken('t')->plainTextToken,
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        // Who typed it is still recorded, which is the point of entered_by.
        $this->assertSame('Kwame Mensah', $response->json('data.0.entered_by'));
    }

    // ── admin review + export ─────────────────────────────────────────────

    public function test_admin_sees_the_submissions_feed(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());

        $this->getJson('/api/v1/admin/partner-sales', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.partner_name', 'Accra Tyre Distributors')
            ->assertJsonPath('data.0.market', 'ghana')
            ->assertJsonPath('data.0.entered_by', 'Kwame Mensah');
    }

    public function test_admin_can_verify_and_dispute(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());
        $sale = PartnerSale::first();

        $this->postJson("/api/v1/admin/partner-sales/{$sale->id}/verify", [], $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        // A dispute must say why — the partner will need to know.
        $this->postJson("/api/v1/admin/partner-sales/{$sale->id}/dispute", [], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->postJson(
            "/api/v1/admin/partner-sales/{$sale->id}/dispute",
            ['note' => 'Quantity does not match the paper report.'],
            $this->adminHeaders(),
        )->assertOk()->assertJsonPath('data.status', 'disputed');
    }

    public function test_the_export_streams_real_csv_not_paginated_json(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload(), $this->partnerHeaders());

        $response = $this->get('/api/v1/admin/partner-sales/export', $this->adminHeaders());

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertSame('date sold', $rows[0][0]);
        $this->assertContains('currency', $rows[0]);
        $this->assertContains('total amount', $rows[0]);

        // Amount, currency and date travel together so finance can apply its
        // own dated rate — nothing in this system converts.
        $this->assertSame('Accra Tyre Distributors', $rows[1][1]);
        $this->assertSame('ghana', $rows[1][2]);
        $this->assertSame('1000.00', $rows[1][10]);
        $this->assertSame('GHS', $rows[1][11]);
    }

    public function test_the_export_respects_its_filters(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload([
            'client_generated_id' => 'in-range', 'sold_at' => now()->subDays(2)->toDateString(),
        ]), $this->partnerHeaders());

        $this->postJson('/api/v1/partner/sales', $this->salePayload([
            'client_generated_id' => 'out-of-range', 'sold_at' => now()->subDays(200)->toDateString(),
        ]), $this->partnerHeaders());

        $from = now()->subDays(7)->toDateString();

        $response = $this->get("/api/v1/admin/partner-sales/export?from={$from}", $this->adminHeaders());
        $rows = array_filter(explode("\n", trim($response->streamedContent())));

        $this->assertCount(2, $rows, 'Header plus exactly one in-range row.');
    }

    public function test_admin_totals_never_combine_currencies(): void
    {
        $this->postJson('/api/v1/partner/sales', $this->salePayload([
            'client_generated_id' => 'dev-uuid-b-1', 'currency' => 'GHS', 'quantity' => 2, 'unit_price' => 100,
        ]), $this->partnerHeaders());

        $this->postJson('/api/v1/partner/sales', $this->salePayload([
            'client_generated_id' => 'dev-uuid-b-2', 'currency' => 'USD', 'quantity' => 2, 'unit_price' => 100,
        ]), $this->partnerHeaders());

        $response = $this->getJson('/api/v1/admin/partner-sales/totals', $this->adminHeaders());

        $response->assertOk();
        $this->assertCount(2, $response->json('data.by_partner'));
        $this->assertCount(2, $response->json('data.by_market'));
    }

    public function test_admin_creates_a_partner_with_its_first_user(): void
    {
        $response = $this->postJson('/api/v1/admin/partners', [
            'name'             => 'Nairobi Tyre Co',
            'country'          => 'Kenya',
            'country_code'     => 'KE',
            'default_currency' => 'KES',
            'owner'            => ['name' => 'Wanjiru Kamau', 'phone' => '+254712345678', 'pin' => '池'],
        ], $this->adminHeaders());

        // A non-numeric PIN is refused.
        $response->assertStatus(422)->assertJsonValidationErrors('owner.pin');

        $this->postJson('/api/v1/admin/partners', [
            'name'             => 'Nairobi Tyre Co',
            'country'          => 'Kenya',
            'country_code'     => 'KE',
            'default_currency' => 'KES',
            'owner'            => ['name' => 'Wanjiru Kamau', 'phone' => '+254712345678', 'pin' => '739184'],
        ], $this->adminHeaders())->assertStatus(201);

        // Phone stored normalised, and the admin-set PIN must be changed on
        // first sign-in because someone else already knows it.
        $user = PartnerUser::where('phone', '254712345678')->first();
        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->must_change_pin);
    }

    public function test_resetting_a_pin_ends_existing_sessions(): void
    {
        $this->getJson('/api/v1/partner/me', $this->partnerHeaders())->assertOk();

        $this->patchJson(
            "/api/v1/admin/partner-users/{$this->partnerUser->id}",
            ['pin' => '927461'],
            $this->adminHeaders(),
        )->assertOk();

        // The old device is signed out — a reset prompted by a suspected
        // compromise must not leave the compromised device logged in.
        $this->getJson('/api/v1/partner/me', $this->partnerHeaders())->assertStatus(401);
    }

    public function test_partner_endpoints_are_closed_to_admins_without_permission(): void
    {
        // `editor` holds none of the partner permissions.
        $this->getJson('/api/v1/admin/partner-sales', $this->adminHeaders('editor'))->assertStatus(403);
        $this->get('/api/v1/admin/partner-sales/export', $this->adminHeaders('editor'))->assertStatus(403);
    }

    // ── catalogue matching ────────────────────────────────────────────────

    public function test_a_size_is_linked_to_the_catalogue_only_when_unambiguous(): void
    {
        \App\Models\Product::create(['brand' => 'Michelin', 'width' => '315', 'height' => '70', 'rim' => '22.5']);

        $this->postJson('/api/v1/partner/sales', $this->salePayload(['client_generated_id' => 'dev-uuid-m-1']), $this->partnerHeaders());
        $this->assertNotNull(PartnerSale::where('client_generated_id', 'dev-uuid-m-1')->first()->product_id);

        // A second matching row makes it ambiguous — better no link than a
        // sale attributed to the wrong SKU in every report.
        \App\Models\Product::create(['brand' => 'Michelin', 'width' => '315', 'height' => '70', 'rim' => '22.5']);

        $this->postJson('/api/v1/partner/sales', $this->salePayload(['client_generated_id' => 'dev-uuid-m-2']), $this->partnerHeaders());
        $this->assertNull(PartnerSale::where('client_generated_id', 'dev-uuid-m-2')->first()->product_id);
    }

    // ── the migration itself ──────────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        // The tables above are hand-built for speed; this runs the REAL
        // migration file, because the schema that ships is the one that has to
        // work. Same approach as BulkEmailCampaignTest's migration tests.
        Schema::disableForeignKeyConstraints();

        foreach (['partner_sale_audits', 'partner_sales', 'partner_users', 'partner_organisations'] as $table) {
            Schema::dropIfExists($table);
            $this->assertFalse(Schema::hasTable($table));
        }

        $migration = require database_path('migrations/2026_08_07_000001_create_partner_sales_tables.php');
        $migration->up();

        foreach (['partner_organisations', 'partner_users', 'partner_sales', 'partner_sale_audits'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} was not created");
        }

        // The columns the whole contract depends on.
        $this->assertTrue(Schema::hasColumn('partner_sales', 'client_generated_id'));
        $this->assertTrue(Schema::hasColumn('partner_sales', 'client_revision'));
        $this->assertTrue(Schema::hasColumn('partner_sales', 'entered_by_user_id'));
        $this->assertTrue(Schema::hasColumn('partner_sales', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('partner_sales', 'total_amount'));

        // Re-running must be a no-op, not an error — every table is guarded.
        $migration->up();

        $this->assertTrue(Schema::hasTable('partner_sales'));
    }

    public function test_the_idempotency_key_is_enforced_by_the_database_not_only_the_controller(): void
    {
        // The controller checks before inserting, but two devices flushing the
        // same queue concurrently can both pass that check. The unique index is
        // what actually prevents the duplicate sale.
        PartnerSale::create([
            'partner_org_id'      => $this->org->id,
            'client_generated_id' => 'dev-uuid-race-01',
            'sold_at'             => now()->toDateString(),
            'size'                => '315/70 R22.5',
            'quantity'            => 1,
            'unit_price'          => 10,
            'total_amount'        => 10,
            'currency'            => 'GHS',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PartnerSale::create([
            'partner_org_id'      => $this->org->id,
            'client_generated_id' => 'dev-uuid-race-01',
            'sold_at'             => now()->toDateString(),
            'size'                => '315/70 R22.5',
            'quantity'            => 1,
            'unit_price'          => 10,
            'total_amount'        => 10,
            'currency'            => 'GHS',
        ]);
    }

    public function test_an_unlisted_size_still_records_the_sale(): void
    {
        // Partners sell tyres Okelcor does not list. That must never block an
        // entry — the free-text size is the source of truth for the books.
        $this->postJson(
            '/api/v1/partner/sales',
            $this->salePayload(['size' => 'some odd size', 'brand' => 'Unknown Brand']),
            $this->partnerHeaders(),
        )->assertStatus(201)->assertJsonPath('data.product_id', null);
    }
}
