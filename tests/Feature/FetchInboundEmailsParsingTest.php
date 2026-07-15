<?php

namespace Tests\Feature;

use App\Console\Commands\FetchInboundEmails;
use Tests\TestCase;

/**
 * Message-parsing helpers on the inbound e-mail command — pure array
 * parsing against Microsoft Graph's clean JSON shape, no DB or network
 * involved, so this runs under the default sqlite testing environment.
 * Made public (not private) specifically so this could be tested directly.
 */
class FetchInboundEmailsParsingTest extends TestCase
{
    private function command(): FetchInboundEmails
    {
        return $this->app->make(FetchInboundEmails::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mail_inbound.address'           => 'support@okelcor.com',
            'services.mail_inbound.message_id_domain' => 'okelcor.com',
        ]);
    }

    public function test_extracts_plus_addressed_message_id_from_to_recipients(): void
    {
        $message = [
            'toRecipients' => [
                ['emailAddress' => ['address' => 'support+abc123uuid@okelcor.com']],
            ],
        ];

        $this->assertSame('abc123uuid@okelcor.com', $this->command()->extractPlusAddressedMessageId($message));
    }

    public function test_returns_null_when_no_plus_address_present(): void
    {
        $message = ['toRecipients' => [['emailAddress' => ['address' => 'support@okelcor.com']]]];

        $this->assertNull($this->command()->extractPlusAddressedMessageId($message));
    }

    public function test_returns_null_when_domain_does_not_match(): void
    {
        $message = ['toRecipients' => [['emailAddress' => ['address' => 'support+abc123@othercompany.com']]]];

        $this->assertNull($this->command()->extractPlusAddressedMessageId($message));
    }

    public function test_extracts_in_reply_to_header_case_insensitively_and_strips_brackets(): void
    {
        $message = [
            'internetMessageHeaders' => [
                ['name' => 'X-Something', 'value' => 'irrelevant'],
                ['name' => 'in-reply-to', 'value' => '<parent-uuid@okelcor.com>'],
            ],
        ];

        $this->assertSame('parent-uuid@okelcor.com', $this->command()->extractInReplyToHeader($message));
    }

    public function test_returns_null_when_no_in_reply_to_header_present(): void
    {
        $message = ['internetMessageHeaders' => [['name' => 'X-Something', 'value' => 'irrelevant']]];

        $this->assertNull($this->command()->extractInReplyToHeader($message));
    }
}
