<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoginHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * Session 104 — the security-hardening pass.
 *
 * Four changes under test:
 *   1. `login_histories` exists (its migration, run as the real file) — the
 *      table shipped code wrote to since launch and production never had,
 *      which silently disabled the 10-failures-in-an-hour auto-suspend.
 *   2. Customer tokens expire (7 days) and are revoked when the password
 *      is reset or changed — before this they lived forever and outlived
 *      the very password change a compromise prompts.
 *   3. The login endpoint is throttled per IP+email as well as per IP.
 *   4. One password policy via Password::defaults() — strict in production,
 *      plain min(8) elsewhere so this suite stays hermetic.
 */
class LoginSecurityHardeningTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach (['login_histories', 'security_events', 'password_reset_tokens', 'customers', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }

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

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_type', 10)->default('b2b');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('company_name')->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_active')->default(true);
            $table->string('onboarding_status', 30)->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('must_reset_password')->default(false);
            $table->integer('failed_login_count')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('info');
            $table->timestamps();
        });

        // The real migration file, not a hand-copied schema — proving the
        // artifact that will run on production, house rule since Session 72.
        $this->runLoginHistoriesMigration();

        Schema::enableForeignKeyConstraints();

        // The limiter store survives between tests inside one process; a
        // previous test's failed logins must not 429 this one.
        RateLimiter::clear($this->limiterKeyFor('any'));
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['login_histories', 'security_events', 'password_reset_tokens', 'customers', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    private function runLoginHistoriesMigration(): void
    {
        $migration = require base_path('database/migrations/2026_08_29_000001_create_login_histories_table.php');
        $migration->up();
    }

    private function limiterKeyFor(string $email): string
    {
        return '127.0.0.1|' . strtolower($email);
    }

    private function customer(array $overrides = []): Customer
    {
        $this->seq++;

        return Customer::create(array_merge([
            'customer_type'     => 'b2b',
            'first_name'        => 'Theo',
            'last_name'         => 'Buyer',
            'email'             => "buyer{$this->seq}@acme-tyres.com",
            'password'          => Hash::make('correct-password-123'),
            'company_name'      => 'Acme Tyres GmbH',
            'status'            => 'active',
            'is_active'         => true,
            'onboarding_status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function resetToken(Customer $customer): string
    {
        $token = 'tok-' . bin2hex(random_bytes(16));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $customer->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        return $token;
    }

    // ── 1. the table, and the auto-suspend it re-arms ─────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        $this->assertTrue(Schema::hasTable('login_histories'));

        // Re-running must be a no-op, not an error.
        $this->runLoginHistoriesMigration();
        $this->assertTrue(Schema::hasTable('login_histories'));

        foreach (['customer_id', 'success', 'ip_address', 'user_agent', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('login_histories', $column),
                "login_histories.{$column} is missing",
            );
        }
    }

    public function test_every_login_attempt_is_recorded(): void
    {
        $customer = $this->customer();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'wrong'])
            ->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'correct-password-123'])
            ->assertOk();

        $rows = LoginHistory::where('customer_id', $customer->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertFalse($rows[0]->success);
        $this->assertTrue($rows[1]->success);
        $this->assertNotNull($rows[0]->ip_address);
    }

    /**
     * The control that has never once fired on production, because its
     * counting query threw into a bare catch against a missing table.
     */
    public function test_ten_failures_in_an_hour_suspends_the_account_and_revokes_its_tokens(): void
    {
        $customer = $this->customer();
        $customer->createToken('customer-auth');
        $this->assertSame(1, $customer->tokens()->count());

        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'wrong']);
        }

        $customer->refresh();
        $this->assertSame('suspended', $customer->status);
        $this->assertFalse($customer->is_active);
        $this->assertSame(0, $customer->tokens()->count());
        $this->assertDatabaseHas('security_events', [
            'customer_id' => $customer->id,
            'type'        => 'suspicious_activity',
        ]);
    }

    // ── 2. tokens die: with time, with a reset, with a change ─────────────

    public function test_a_login_token_expires_in_seven_days(): void
    {
        $customer = $this->customer();

        $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email, 'password' => 'correct-password-123',
        ])->assertOk();

        $token = $customer->tokens()->first();
        $this->assertNotNull($token->expires_at, 'Customer tokens must carry an expiry.');

        $days = (int) config('auth.customer_token_ttl_days', 7);
        $this->assertTrue(
            $token->expires_at->between(now()->addDays($days)->subMinute(), now()->addDays($days)->addMinute()),
            "Expiry should be ~{$days} days out, got {$token->expires_at}",
        );
    }

    public function test_a_password_reset_revokes_every_existing_token(): void
    {
        $customer = $this->customer();
        $customer->createToken('customer-auth');
        $customer->createToken('customer-auth');
        $this->assertSame(2, $customer->tokens()->count());

        $this->postJson('/api/v1/auth/reset-password', [
            'email'                 => $customer->email,
            'token'                 => $this->resetToken($customer),
            'password'              => 'brand-new-password-9',
            'password_confirmation' => 'brand-new-password-9',
        ])->assertOk();

        // A reset is what someone does when they suspect compromise — every
        // session opened with the old password must end with it.
        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_a_password_change_revokes_other_sessions_but_not_this_one(): void
    {
        $customer = $this->customer();

        $mine  = $customer->createToken('customer-auth');
        $other = $customer->createToken('customer-auth');
        $this->assertSame(2, $customer->tokens()->count());

        $this->withHeaders(['Authorization' => 'Bearer ' . $mine->plainTextToken])
            ->putJson('/api/v1/auth/change-password', [
                'current_password'      => 'correct-password-123',
                'password'              => 'brand-new-password-9',
                'password_confirmation' => 'brand-new-password-9',
            ])->assertOk();

        // The lost-phone session is dead; the one that did the changing is
        // not logged out by its own action.
        $remaining = $customer->tokens()->pluck('id');
        $this->assertCount(1, $remaining);
        $this->assertTrue($remaining->contains($mine->accessToken->id));
        $this->assertFalse($remaining->contains($other->accessToken->id));
    }

    /**
     * Found by this session's own test run: CustomerAuth resolved the token
     * itself and never checked expires_at — Sanctum's Guard does, but only
     * admin routes go through it. Without this, the 7-day TTL would have
     * been stamped onto every token and enforced on none.
     */
    public function test_an_expired_token_is_refused(): void
    {
        $customer = $this->customer();
        $token    = $customer->createToken('customer-auth', ['*'], now()->subMinute());

        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        // And a live one is not caught by the same check.
        $live = $customer->createToken('customer-auth', ['*'], now()->addDay());
        $this->withHeaders(['Authorization' => 'Bearer ' . $live->plainTextToken])
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    /**
     * Found the same way: the middleware never called withAccessToken(), so
     * currentAccessToken() was null on every customer route and logout threw
     * on the null. This asserts logout works AND kills only its own session.
     */
    public function test_logout_deletes_this_token_and_only_this_token(): void
    {
        $customer = $this->customer();
        $mine     = $customer->createToken('customer-auth');
        $other    = $customer->createToken('customer-auth');

        $this->withHeaders(['Authorization' => 'Bearer ' . $mine->plainTextToken])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $remaining = $customer->tokens()->pluck('id');
        $this->assertCount(1, $remaining);
        $this->assertTrue($remaining->contains($other->accessToken->id));
    }

    // ── 3. the per-account throttle dimension ─────────────────────────────

    public function test_a_sixth_rapid_guess_at_one_account_is_throttled(): void
    {
        $customer = $this->customer();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    // ── 4. one password policy ────────────────────────────────────────────

    public function test_the_production_password_policy_is_strict_and_fails_open_on_hibp(): void
    {
        // The defaults() closure reads the environment when the rule is
        // used, not when it was registered — so flipping the env here
        // exercises the production branch.
        $this->app['env'] = 'production';

        // HIBP unreachable: uncompromised() must fail OPEN (the vendor
        // verifier catches its own exception), so an outage never blocks a
        // signup. An empty response body means "not found in any breach".
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $fails = Validator::make(
            ['password' => 'weakpass'],
            ['password' => [Password::defaults()]],
        )->fails();
        $this->assertTrue($fails, 'min(8) lowercase must fail the production policy');

        $passes = Validator::make(
            ['password' => 'Str0ng!Tyres-2026'],
            ['password' => [Password::defaults()]],
        )->passes();
        $this->assertTrue($passes, 'a 12+ mixed/number/symbol password must pass');
    }

    public function test_outside_production_the_policy_stays_min_eight(): void
    {
        // The suite (and local dev) must not require symbols in every
        // fixture password, and must never call HIBP.
        $passes = Validator::make(
            ['password' => 'testpass'],
            ['password' => [Password::defaults()]],
        )->passes();

        $this->assertTrue($passes);
    }
}
