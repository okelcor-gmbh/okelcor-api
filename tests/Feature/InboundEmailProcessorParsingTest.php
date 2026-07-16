<?php

namespace Tests\Feature;

use App\Services\InboundEmailProcessor;
use Tests\TestCase;

/**
 * Message-parsing helpers on InboundEmailProcessor — pure array parsing
 * against a plain, transport-agnostic shape (deliberately the same shape
 * regardless of whether IMAP, Microsoft Graph, or — currently — a
 * Cloudflare Email Worker webhook delivered the message). No DB or network
 * involved, so this runs under the default sqlite testing environment.
 * Made public (not private) specifically so this could be tested directly.
 */
class InboundEmailProcessorParsingTest extends TestCase
{
    private function processor(): InboundEmailProcessor
    {
        return $this->app->make(InboundEmailProcessor::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mail_inbound.address'           => 'reply@reply.okelcor.com',
            'services.mail_inbound.message_id_domain' => 'okelcor.com',
        ]);
    }

    public function test_extracts_plus_addressed_message_id_from_to_recipients(): void
    {
        $message = [
            'toRecipients' => [
                ['emailAddress' => ['address' => 'reply+abc123uuid@reply.okelcor.com']],
            ],
        ];

        $this->assertSame('abc123uuid@okelcor.com', $this->processor()->extractPlusAddressedMessageId($message));
    }

    public function test_returns_null_when_no_plus_address_present(): void
    {
        $message = ['toRecipients' => [['emailAddress' => ['address' => 'reply@reply.okelcor.com']]]];

        $this->assertNull($this->processor()->extractPlusAddressedMessageId($message));
    }

    public function test_returns_null_when_domain_does_not_match(): void
    {
        $message = ['toRecipients' => [['emailAddress' => ['address' => 'reply+abc123@othercompany.com']]]];

        $this->assertNull($this->processor()->extractPlusAddressedMessageId($message));
    }

    public function test_extracts_in_reply_to_header_case_insensitively_and_strips_brackets(): void
    {
        $message = [
            'internetMessageHeaders' => [
                ['name' => 'X-Something', 'value' => 'irrelevant'],
                ['name' => 'in-reply-to', 'value' => '<parent-uuid@okelcor.com>'],
            ],
        ];

        $this->assertSame('parent-uuid@okelcor.com', $this->processor()->extractInReplyToHeader($message));
    }

    public function test_returns_null_when_no_in_reply_to_header_present(): void
    {
        $message = ['internetMessageHeaders' => [['name' => 'X-Something', 'value' => 'irrelevant']]];

        $this->assertNull($this->processor()->extractInReplyToHeader($message));
    }
}
