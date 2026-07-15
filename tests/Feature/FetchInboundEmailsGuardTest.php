<?php

namespace Tests\Feature;

use App\Console\Commands\FetchInboundEmails;
use Tests\TestCase;

/**
 * The own-domain guard is the safety net that makes it safe to use
 * support@okelcor.com (shared with ORDER_EMAIL/QUOTE_EMAIL/CRM_DIGEST_EMAIL)
 * as the inbound-capture mailbox instead of a brand-new dedicated address —
 * without it, the system's own automated notifications landing in that same
 * inbox would be mistaken for customer replies and spawn bogus leads. Pure
 * logic, no IMAP/DB involved, so this runs under the default sqlite env.
 */
class FetchInboundEmailsGuardTest extends TestCase
{
    private function command(): FetchInboundEmails
    {
        return $this->app->make(FetchInboundEmails::class);
    }

    public function test_recognizes_own_domain_sender_via_configured_from_address(): void
    {
        config(['mail.from.address' => 'noreply@okelcor.com']);

        $this->assertTrue($this->command()->isOwnDomainSender('noreply@okelcor.com'));
        $this->assertTrue($this->command()->isOwnDomainSender('support@okelcor.com'));
        $this->assertTrue($this->command()->isOwnDomainSender('jane@OKELCOR.COM')); // case-insensitive
    }

    public function test_does_not_flag_a_real_customer_domain(): void
    {
        config(['mail.from.address' => 'noreply@okelcor.com']);

        $this->assertFalse($this->command()->isOwnDomainSender('buyer@acme-tyres.com'));
    }

    public function test_explicit_own_domain_override_takes_precedence(): void
    {
        config([
            'mail.from.address'                 => 'noreply@okelcor.com',
            'services.mail_inbound.own_domain'   => 'okelcor-internal.com',
        ]);

        $this->assertTrue($this->command()->isOwnDomainSender('anyone@okelcor-internal.com'));
        $this->assertFalse($this->command()->isOwnDomainSender('anyone@okelcor.com'));
    }

    public function test_does_not_falsely_match_a_similar_but_different_domain(): void
    {
        config(['mail.from.address' => 'noreply@okelcor.com']);

        // "notokelcor.com" ends with "okelcor.com" as a raw substring but is
        // a different domain — the check must require the "@" boundary.
        $this->assertFalse($this->command()->isOwnDomainSender('buyer@notokelcor.com'));
    }
}
