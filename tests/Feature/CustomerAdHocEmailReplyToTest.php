<?php

namespace Tests\Feature;

use App\Mail\CustomerAdHocEmail;
use App\Models\AdminUser;
use Tests\TestCase;

/**
 * Reply-to address construction — the actual fix for "customer replies only
 * land in Outlook, not the system": when inbound capture is configured, the
 * outgoing e-mail's Reply-To must be a plus-addressed variant of the shared
 * inbound mailbox (so FetchInboundEmails can match the reply back to this
 * exact message), and must fall back to the sending admin's own address
 * when inbound capture isn't configured — so this ships safely ahead of
 * that setup being finished. Building/inspecting a Mailable's envelope
 * needs no database, so (like the sanitizer/WhatsApp service tests) this
 * runs under the default sqlite testing environment.
 */
class CustomerAdHocEmailReplyToTest extends TestCase
{
    private function admin(): AdminUser
    {
        // Unsaved model — envelope()/content() only read these properties
        // directly (content() calls ->fresh(), which no-ops safely on a
        // model with no primary key), no DB round trip needed.
        return new AdminUser(['name' => 'Jane Ops', 'email' => 'jane@okelcor.test']);
    }

    private function mailable(?string $inReplyTo = null): CustomerAdHocEmail
    {
        return new CustomerAdHocEmail(
            sender: $this->admin(),
            subjectLine: 'Test subject',
            bodyHtml: '<p>Hello</p>',
            ccRecipients: [],
            attachmentFiles: [],
            messageId: 'abc123uuid@okelcor.com',
            inReplyTo: $inReplyTo,
        );
    }

    public function test_reply_to_falls_back_to_sender_when_inbound_capture_disabled(): void
    {
        config(['services.mail_inbound.enabled' => false]);

        $replyTo = $this->mailable()->envelope()->replyTo;

        $this->assertCount(1, $replyTo);
        $this->assertSame('jane@okelcor.test', $replyTo[0]->address);
    }

    public function test_reply_to_is_plus_addressed_when_inbound_capture_enabled(): void
    {
        config([
            'services.mail_inbound.enabled' => true,
            'services.mail_inbound.address' => 'support@okelcor.com',
        ]);

        $replyTo = $this->mailable()->envelope()->replyTo;

        $this->assertCount(1, $replyTo);
        $this->assertSame('support+abc123uuid@okelcor.com', $replyTo[0]->address);
    }

    public function test_reply_to_falls_back_when_enabled_but_address_missing(): void
    {
        config([
            'services.mail_inbound.enabled' => true,
            'services.mail_inbound.address' => null,
        ]);

        $replyTo = $this->mailable()->envelope()->replyTo;

        $this->assertSame('jane@okelcor.test', $replyTo[0]->address);
    }

    public function test_message_id_and_in_reply_to_headers(): void
    {
        $mail    = $this->mailable(inReplyTo: 'parent-uuid@okelcor.com');
        $headers = $mail->headers();

        $this->assertSame('abc123uuid@okelcor.com', $headers->messageId);
        $this->assertSame(['parent-uuid@okelcor.com'], $headers->references);
        $this->assertSame('<parent-uuid@okelcor.com>', $headers->text['In-Reply-To']);
    }

    public function test_no_in_reply_to_header_when_not_a_reply(): void
    {
        $headers = $this->mailable()->headers();

        $this->assertSame([], $headers->references);
        $this->assertArrayNotHasKey('In-Reply-To', $headers->text);
    }
}
