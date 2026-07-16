<?php

namespace Tests\Feature;

use App\Services\InboundEmailProcessor;
use Tests\TestCase;

/**
 * The own-domain guard is the safety net that makes it safe to use a
 * mailbox that also receives other automated system mail (or, in earlier
 * designs, support@okelcor.com shared with ORDER_EMAIL/QUOTE_EMAIL/
 * CRM_DIGEST_EMAIL) as the inbound-capture source — without it, the
 * system's own automated notifications would be mistaken for customer
 * replies and spawn bogus leads. Pure logic, no IMAP/HTTP/DB involved, so
 * this runs under the default sqlite env. Unaffected by which transport
 * (IMAP, Graph, or the current Cloudflare Worker webhook) delivers the
 * message — this lives on the shared InboundEmailProcessor, not any one
 * transport's class.
 */
class InboundEmailProcessorGuardTest extends TestCase
{
    private function processor(): InboundEmailProcessor
    {
        return $this->app->make(InboundEmailProcessor::class);
    }

    public function test_recognizes_own_domain_sender_via_configured_from_address(): void
    {
        config(['mail.from.address' => 'noreply@okelcor.com']);

        $this->assertTrue($this->processor()->isOwnDomainSender('noreply@okelcor.com'));
        $this->assertTrue($this->processor()->isOwnDomainSender('support@okelcor.com'));
        $this->assertTrue($this->processor()->isOwnDomainSender('jane@OKELCOR.COM')); // case-insensitive
    }

    public function test_does_not_flag_a_real_customer_domain(): void
    {
        config(['mail.from.address' => 'noreply@okelcor.com']);

        $this->assertFalse($this->processor()->isOwnDomainSender('buyer@acme-tyres.com'));
    }

    public function test_explicit_own_domain_override_takes_precedence(): void
    {
        config([
            'mail.from.address'                => 'noreply@okelcor.com',
            'services.mail_inbound.own_domain'  => 'okelcor-internal.com',
        ]);

        $this->assertTrue($this->processor()->isOwnDomainSender('anyone@okelcor-internal.com'));
        $this->assertFalse($this->processor()->isOwnDomainSender('anyone@okelcor.com'));
    }

    public function test_does_not_falsely_match_a_similar_but_different_domain(): void
    {
        config(['mail.from.address' => 'noreply@okelcor.com']);

        // "notokelcor.com" ends with "okelcor.com" as a raw substring but is
        // a different domain — the check must require the "@" boundary.
        $this->assertFalse($this->processor()->isOwnDomainSender('buyer@notokelcor.com'));
    }
}
