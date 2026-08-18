<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerEmailVerifier;
use App\Services\SecurityEventService;
use Illuminate\Console\Command;

/**
 * Find customers who cannot log in, and say which gate is stopping each one.
 *
 * Written because a buyer went round the same loop several times before anyone
 * at Okelcor could tell him what was actually wrong: his account was approved,
 * login demanded he verify his email, the verification link from registration
 * had expired a fortnight earlier, and the password reset he kept completing
 * did not count as verification. Nothing in the product could see that, so the
 * only way to find him was for him to complain.
 *
 *   php artisan customers:stuck                       — everyone who cannot log in
 *   php artisan customers:stuck buyer@example.com     — one account, in detail
 *   php artisan customers:stuck buyer@example.com --resend-verification
 *   php artisan customers:stuck buyer@example.com --verify --reason="..."
 *
 * The sweep reports and never repairs. Several of these gates are deliberate —
 * a rejected application and an unverified address look identical from here and
 * only one of them should be cleared.
 */
class AuditStuckCustomerLogins extends Command
{
    protected $signature = 'customers:stuck
                            {email?                 : One customer, in detail}
                            {--resend-verification  : Send that customer a fresh confirmation link}
                            {--verify               : Confirm the address on their behalf}
                            {--reason=              : Why (required with --verify)}';

    protected $description = 'Find customers who cannot log in and say which gate is stopping each one.';

    public function __construct(private CustomerEmailVerifier $verifier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = $this->argument('email');

        return $email ? $this->one((string) $email) : $this->sweep();
    }

    // -------------------------------------------------------------------------

    /**
     * The first gate in CustomerAuthController::login this customer would hit,
     * or null if they can get in.
     *
     * Ordered exactly as the controller checks them, so what this prints is
     * what the customer is actually being told rather than a second opinion
     * about it.
     *
     * @return array{gate: string, detail: string, fix: string}|null
     */
    private function blockedBy(Customer $c): ?array
    {
        $status = $c->status ?? ($c->is_active ? 'active' : 'inactive');

        if ($status !== 'active') {
            return [
                'gate'   => 'status = ' . $status,
                'detail' => match ($status) {
                    'locked'    => 'Five wrong passwords. There is no time-based expiry — it stays locked until cleared.',
                    'suspended' => 'Suspended, by an admin or by ten failed logins in an hour.',
                    'banned'    => 'Banned by an admin.',
                    default     => 'Account is not active.',
                },
                'fix'    => $status === 'locked' ? 'Admin Actions → Unlock Account' : 'Admin Actions → Activate Account',
            ];
        }

        $onboarding = $c->onboarding_status ?? 'active';

        if ($onboarding !== 'active') {
            return [
                'gate'   => 'onboarding_status = ' . $onboarding,
                'detail' => match ($onboarding) {
                    'pending_review' => 'Registered, never reviewed.',
                    'approved'       => 'Approved but never invited — they have no password.',
                    'invited'        => 'Invited; the invitation has not been completed.',
                    'rejected'       => 'Application refused. Deliberate — do not clear without asking.',
                    'blocked'        => 'Blocked by an admin. Deliberate.',
                    default          => 'Not yet active.',
                },
                'fix'    => in_array($onboarding, ['rejected', 'blocked'], true)
                    ? '(deliberate — leave alone unless the business says otherwise)'
                    : 'Admin Actions → Approve / Send Invitation',
            ];
        }

        if ($c->email_verified_at === null) {
            return [
                'gate'   => 'email not confirmed',
                'detail' => 'Approved and active, but the address was never confirmed. The registration link lasts '
                    . CustomerEmailVerifier::LINK_TTL_HOURS . ' hours, so for anyone approved later it is long dead.',
                'fix'    => 'Admin Actions → Resend Confirmation Email (or Confirm Email Address)',
            ];
        }

        if ($c->must_reset_password) {
            return [
                'gate'   => 'must_reset_password',
                'detail' => 'Login refuses until a new password is set.',
                'fix'    => 'Admin Actions → Force Password Reset',
            ];
        }

        return null;
    }

    private function one(string $email): int
    {
        $customer = Customer::where('email', $email)->first();

        if (! $customer) {
            $this->error("No customer with the email '{$email}'.");

            return self::FAILURE;
        }

        $blocked = $this->blockedBy($customer);

        $this->line('');
        $this->table(['Field', 'Value'], [
            ['Name',               $customer->full_name ?? '—'],
            ['Email',              $customer->email],
            ['Company',            $customer->company_name ?? '—'],
            ['Account status',     $customer->status ?? '—'],
            ['Onboarding status',  $customer->onboarding_status ?? '—'],
            ['Email confirmed',    $customer->email_verified_at?->toDateTimeString() ?? 'NO'],
            ['Must reset password', $customer->must_reset_password ? 'yes' : 'no'],
            ['Failed logins',      (int) ($customer->failed_login_count ?? 0)],
            ['Last login',         $customer->last_login_at?->toDateTimeString() ?? 'never'],
            ['Registered',         $customer->created_at?->toDateTimeString() ?? '—'],
        ]);

        $this->line('');

        if (! $blocked) {
            $this->info('Nothing is blocking this account. If the customer still cannot get in, it is the password.');
        } else {
            $this->warn('BLOCKED BY: ' . $blocked['gate']);
            $this->line('  ' . $blocked['detail']);
            $this->line('  Fix: ' . $blocked['fix']);
        }

        if ($this->option('verify')) {
            return $this->verify($customer);
        }

        if ($this->option('resend-verification')) {
            return $this->resend($customer);
        }

        return self::SUCCESS;
    }

    private function resend(Customer $customer): int
    {
        if ($customer->email_verified_at) {
            $this->error('That address is already confirmed — a link would not help.');

            return self::FAILURE;
        }

        if (! $this->verifier->send($customer)) {
            $this->error('The verification email could not be sent. Check the mail log.');

            return self::FAILURE;
        }

        SecurityEventService::log(
            'email_verification_sent', $customer->id, null, null,
            'Verification email resent via console', 'info'
        );

        $this->line('');
        $this->info("Sent. The link reaches {$customer->email} and is good for "
            . CustomerEmailVerifier::LINK_TTL_HOURS . ' hours.');

        return self::SUCCESS;
    }

    private function verify(Customer $customer): int
    {
        $reason = (string) ($this->option('reason') ?: '');

        if (strlen($reason) < 5) {
            $this->error('--reason is required (min 5 characters) — this vouches for an address on the customer\'s behalf.');

            return self::FAILURE;
        }

        if ($customer->email_verified_at) {
            $this->error('That address is already confirmed.');

            return self::FAILURE;
        }

        $customer->update(['email_verified_at' => now()]);

        SecurityEventService::log(
            'email_verified_by_admin', $customer->id, null, null,
            'Email confirmed via console. Reason: ' . $reason, 'warning'
        );

        $this->line('');
        $this->info("Confirmed. {$customer->email} can now log in, provided the account is active.");

        return self::SUCCESS;
    }

    private function sweep(): int
    {
        $rows = [];

        Customer::query()->orderBy('id')->chunk(200, function ($customers) use (&$rows) {
            foreach ($customers as $c) {
                if ($blocked = $this->blockedBy($c)) {
                    $rows[] = [
                        $c->email,
                        $c->company_name ?? '—',
                        $blocked['gate'],
                        $c->created_at?->toDateString() ?? '—',
                        $c->last_login_at?->toDateString() ?? 'never',
                        $blocked['fix'],
                    ];
                }
            }
        });

        $this->line('');

        if (! $rows) {
            $this->info('No customer is blocked from logging in.');

            return self::SUCCESS;
        }

        $this->warn(count($rows) . ' customer(s) cannot log in:');
        $this->line('');
        $this->table(['Email', 'Company', 'Blocked by', 'Registered', 'Last login', 'Fix'], $rows);

        $this->line('');
        $this->line('Not all of these are faults. A rejected application and a blocked account are');
        $this->line('supposed to be here; an approved buyer with an unconfirmed address is not.');
        $this->line('');
        $this->line('One account in detail:  php artisan customers:stuck <email>');

        return self::SUCCESS;
    }
}
