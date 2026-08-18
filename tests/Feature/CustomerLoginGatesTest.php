<?php

namespace Tests\Feature;

use App\Mail\ApprovedAccountEmail;
use App\Mail\CustomerEmailVerification;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\SecurityEvent;
use App\Services\CustomerApprovalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The approved customer who could never get in.
 *
 * Reported through an order manager, in the customer's own words: *"I can see
 * the account was approved, but each time I try to log in, I keep getting the
 * attached message. I have completed a few of these verifications that prompt a
 * password change, but the login still prompts me to verify my email again."*
 * The attachment was the "Please verify your email" page.
 *
 * Three faults compounding, none of which anybody could see from the admin panel:
 *
 *  1. `resetPassword` stamped `email_verified_at` only for customers whose
 *     onboarding_status was 'invited'. A self-registered buyer approved by an
 *     admin is set to 'active' (CustomerApprovalService::activationUpdates),
 *     so completing a reset proved he owned the mailbox and then recorded
 *     nothing. Login kept refusing. That is the loop he described.
 *
 *  2. The approval email said "please verify your email address first" and
 *     shipped no link. The only verification link he had ever been sent was
 *     from registration, and it expires after 24 hours — B2B accounts are
 *     routinely approved days later.
 *
 *  3. The order manager had no control for any of it. Suspend, ban, unlock and
 *     force-password-reset all have buttons; email confirmation had none, so
 *     the only route out was a developer.
 *
 * Minimal-schema sqlite harness — the full migration set contains MySQL-only
 * legacy migrations, same reason as PaymentMilestoneControlTest.
 */
class CustomerLoginGatesTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Schema::disableForeignKeyConstraints();

        foreach (['security_events', 'password_reset_tokens', 'customers', 'personal_access_tokens', 'admin_users'] as $table) {
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

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_type', 10)->default('b2b');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('company_name')->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_active')->default(true);
            $table->string('onboarding_status', 30)->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('must_reset_password')->default(false);
            $table->integer('failed_login_count')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('access_level', 30)->nullable();
            $table->boolean('approved_for_quotes')->default(false);
            $table->boolean('approved_for_checkout')->default(false);
            $table->boolean('approved_for_documents')->default(false);
            $table->boolean('approved_for_wholesale_pricing')->default(false);
            $table->string('buyer_tier', 20)->nullable();
            $table->string('verification_status', 20)->nullable();
            $table->string('risk_level', 20)->nullable();
            $table->integer('health_score')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
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
    }

    private function customer(array $overrides = []): Customer
    {
        $this->seq++;

        return Customer::create(array_merge([
            'customer_type'     => 'b2b',
            'first_name'        => 'Theo',
            'last_name'         => 'Buyer',
            'email'             => "buyer{$this->seq}@acme-tyres.com",
            'password'          => Hash::make('old-password-123'),
            'country'           => 'DE',
            'company_name'      => 'Acme Tyres GmbH',
            'status'            => 'active',
            'is_active'         => true,
            'onboarding_status' => 'active',
            'email_verified_at' => null,
        ], $overrides));
    }

    /** A live reset token for that customer, as forgot-password would create. */
    private function resetToken(Customer $customer): string
    {
        $token = 'tok-' . bin2hex(random_bytes(16));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $customer->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        return $token;
    }

    private function adminHeaders(string $role = 'admin'): array
    {
        $this->seq++;

        $admin = AdminUser::create([
            'name'                    => 'Ops ' . $this->seq,
            'email'                   => "ops{$this->seq}@okelcor.test",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    // ── 1. the loop the customer was stuck in ─────────────────────────────

    public function test_completing_a_password_reset_confirms_the_email_address(): void
    {
        // The exact shape of the reported account: approved, active, never
        // verified, onboarding_status 'active' rather than 'invited'.
        $customer = $this->customer();

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $this->resetToken($customer),
            'email'                 => $customer->email,
            'password'              => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertOk();

        $this->assertNotNull(
            $customer->fresh()->email_verified_at,
            'A reset token is mailed to that address and returned by whoever opened it — that is the same proof a verification link collects.'
        );
    }

    public function test_the_customer_can_then_actually_log_in(): void
    {
        // The assertion that matters. Everything else is mechanism; this is
        // what he was trying to do and could not.
        $customer = $this->customer();

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $this->resetToken($customer),
            'email'                 => $customer->email,
            'password'              => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email'    => $customer->email,
            'password' => 'brand-new-password-1',
        ])->assertOk();
    }

    public function test_before_the_fix_the_same_account_is_refused_at_the_email_gate(): void
    {
        // Guards the gate itself: an unverified account must still be refused,
        // and refused with the message the customer sent us. The fix is that a
        // reset now clears it, not that the check went away.
        $customer = $this->customer();

        $this->postJson('/api/v1/auth/login', [
            'email'    => $customer->email,
            'password' => 'old-password-123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('email_verified', false);
    }

    public function test_an_address_verified_earlier_keeps_its_original_date(): void
    {
        // This records when the mailbox was first proved, not when it was last
        // used. Overwriting would quietly rewrite the account's history every
        // time somebody forgot their password.
        $verifiedAt = now()->subMonths(6)->startOfSecond();
        $customer   = $this->customer(['email_verified_at' => $verifiedAt]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $this->resetToken($customer),
            'email'                 => $customer->email,
            'password'              => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertOk();

        $this->assertSame(
            $verifiedAt->toDateTimeString(),
            $customer->fresh()->email_verified_at->toDateTimeString()
        );
    }

    public function test_an_invited_customer_still_activates_on_setting_a_password(): void
    {
        // The branch that already worked. Reordering the stamp out of it must
        // not cost the invite flow its activation.
        $customer = $this->customer([
            'onboarding_status'   => 'invited',
            'is_active'           => false,
            'must_reset_password' => true,
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $this->resetToken($customer),
            'email'                 => $customer->email,
            'password'              => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertOk();

        $fresh = $customer->fresh();

        $this->assertSame('active', $fresh->onboarding_status);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertFalse((bool) $fresh->must_reset_password);
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_an_expired_reset_token_verifies_nothing(): void
    {
        $customer = $this->customer();
        $token    = $this->resetToken($customer);

        DB::table('password_reset_tokens')
            ->where('email', $customer->email)
            ->update(['created_at' => now()->subHours(3)]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $customer->email,
            'password'              => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertStatus(422);

        $this->assertNull($customer->fresh()->email_verified_at);
    }

    // ── 2. the approval email carries a link ──────────────────────────────

    public function test_approving_an_unverified_buyer_sends_a_usable_link(): void
    {
        // It used to say "please verify your email address first" and enclose
        // nothing, while the only link the customer had ever received expired
        // 24 hours after registration.
        $customer = $this->customer();

        app(CustomerApprovalService::class)->sendApprovalEmail($customer, 'approved_buyer');

        Mail::assertSent(ApprovedAccountEmail::class, function (ApprovedAccountEmail $mail) use ($customer) {
            return $mail->hasTo($customer->email)
                && $mail->requiresEmailVerification === true
                && is_string($mail->verifyUrl)
                && str_contains($mail->verifyUrl, 'signature=');
        });
    }

    public function test_an_already_verified_buyer_gets_no_verification_link(): void
    {
        $customer = $this->customer(['email_verified_at' => now()]);

        app(CustomerApprovalService::class)->sendApprovalEmail($customer, 'approved_buyer');

        Mail::assertSent(ApprovedAccountEmail::class, fn (ApprovedAccountEmail $mail) =>
            $mail->requiresEmailVerification === false && $mail->verifyUrl === null);
    }

    // ── 3. the order manager can fix it herself ───────────────────────────

    public function test_an_admin_can_resend_the_confirmation_link(): void
    {
        $customer = $this->customer();

        $this->postJson("/api/v1/admin/customers/{$customer->id}/resend-verification", [], $this->adminHeaders())
            ->assertOk();

        Mail::assertSent(CustomerEmailVerification::class, fn ($mail) => $mail->hasTo($customer->email));
    }

    public function test_resending_to_an_already_confirmed_address_is_refused(): void
    {
        // Sending it anyway would tell the order manager she had fixed
        // something, when the customer's problem is somewhere else entirely.
        $customer = $this->customer(['email_verified_at' => now()]);

        $this->postJson("/api/v1/admin/customers/{$customer->id}/resend-verification", [], $this->adminHeaders())
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_verified');

        Mail::assertNotSent(CustomerEmailVerification::class);
    }

    public function test_an_admin_can_confirm_the_address_on_the_customers_behalf(): void
    {
        $customer = $this->customer();

        $this->postJson("/api/v1/admin/customers/{$customer->id}/verify-email", [
            'reason' => 'Corporate mail filter is eating the link; we have corresponded with this address for months.',
        ], $this->adminHeaders())->assertOk();

        $this->assertNotNull($customer->fresh()->email_verified_at);

        $this->postJson('/api/v1/auth/login', [
            'email'    => $customer->email,
            'password' => 'old-password-123',
        ])->assertOk();
    }

    public function test_vouching_for_an_address_requires_a_reason(): void
    {
        $customer = $this->customer();

        $this->postJson("/api/v1/admin/customers/{$customer->id}/verify-email", [], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertNull($customer->fresh()->email_verified_at);
    }

    public function test_an_admin_confirmation_is_recorded_as_its_own_kind_of_event(): void
    {
        // Distinct from 'email_verified' on purpose: one is the customer
        // proving it, the other is Okelcor asserting it. An audit trail that
        // could not tell them apart would be worth less than none.
        $customer = $this->customer();

        $this->postJson("/api/v1/admin/customers/{$customer->id}/verify-email", [
            'reason' => 'Address confirmed by phone with the buyer.',
        ], $this->adminHeaders())->assertOk();

        $event = SecurityEvent::where('customer_id', $customer->id)
            ->where('type', 'email_verified_by_admin')
            ->first();

        $this->assertNotNull($event);
        $this->assertStringContainsString('Address confirmed by phone', (string) $event->description);
        $this->assertSame('warning', $event->severity);
    }

    public function test_a_role_without_customer_management_cannot_confirm_an_address(): void
    {
        $customer = $this->customer();

        $this->postJson("/api/v1/admin/customers/{$customer->id}/verify-email", [
            'reason' => 'Should never be applied.',
        ], $this->adminHeaders('viewer'))->assertStatus(403);

        $this->assertNull($customer->fresh()->email_verified_at);
    }

    // ── 4. the column can hold what the code writes ───────────────────────

    public function test_every_security_event_type_written_in_app_is_allowed_by_the_column(): void
    {
        // The fourth instance of this project's longest-running failure mode,
        // and the one that would have bitten hardest: SecurityEventService does
        // NOT swallow its exceptions, so a type missing from the ENUM does not
        // lose an audit row on strict MySQL — it throws and fails the customer
        // action that triggered it. Writing 'email_verified' from the
        // password-reset path without the migration would have broken
        // completing a password reset outright.
        //
        // Same guard OrderLog::ACTIONS got in Session 83, for the column that
        // was explicitly left out of it and has been a Known Gap since.
        $written = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (preg_match_all("/SecurityEventService::log\(\s*'([a-z_]+)'/", $source, $matches)) {
                $written = array_merge($written, $matches[1]);
            }
        }

        $written = array_values(array_unique($written));

        $this->assertNotEmpty($written, 'The scan found no event types at all — the pattern has drifted.');

        $missing = array_values(array_diff($written, SecurityEvent::TYPES));

        $this->assertSame([], $missing,
            'These types are written in app/ but are not in SecurityEvent::TYPES, so the ENUM will reject them '
            . 'and throw: ' . implode(', ', $missing));
    }
}
