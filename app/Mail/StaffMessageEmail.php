<?php

namespace App\Mail;

use App\Models\AdminUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The e-mail copy of an internal staff-to-staff message.
 *
 * The message itself lives in the admin panel; this is what lands in the
 * colleague's real mailbox so they see it without being logged in.
 *
 * Reply-To is the SENDER'S OWN ADDRESS, deliberately — unlike
 * CustomerAdHocEmail, which plus-addresses replies back into the system.
 * InboundEmailProcessor::isOwnDomainSender() silently drops any mail sent
 * from an okelcor.com address (so the app's own order/quote notifications
 * can't spawn fake leads), which would swallow a staff reply sent from
 * Outlook without a trace. Pointing Reply-To at the sender means hitting
 * reply in Outlook is a normal e-mail between two colleagues — outside the
 * system, but delivered. Replying inside the panel keeps it threaded.
 *
 * @param array<int, array{path:string, name:string, mime:string}> $attachmentFiles
 */
class StaffMessageEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly AdminUser $sender,
        public readonly string $subjectLine,
        public readonly string $bodyHtml,
        public readonly array $attachmentFiles,
        public readonly string $panelUrl,
        public readonly ?string $forwardedContext = null,
    ) {}

    public function envelope(): Envelope
    {
        $senderName = trim($this->sender->display_name ?: $this->sender->name) ?: $this->sender->email;

        return new Envelope(
            from: new Address(
                config('mail.from.address', 'support@okelcor.com'),
                $senderName . ' (Okelcor)',
            ),
            replyTo: [$this->sender->email],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $senderName = trim($this->sender->display_name ?: $this->sender->name) ?: $this->sender->email;

        return new Content(
            view: 'emails.staff-message',
            text: 'emails.staff-message-text',
            with: [
                'bodyHtml'         => $this->bodyHtml,
                'bodyText'         => $this->toPlainText($this->bodyHtml),
                'senderName'       => $senderName,
                'senderEmail'      => $this->sender->email,
                'panelUrl'         => $this->panelUrl,
                'forwardedContext' => $this->forwardedContext,
            ],
        );
    }

    public function attachments(): array
    {
        return collect($this->attachmentFiles)
            ->map(fn (array $a) => Attachment::fromPath($a['path'])->as($a['name'])->withMime($a['mime']))
            ->all();
    }

    private function toPlainText(string $html): string
    {
        $withBreaks = preg_replace('#<(br|/p|/div|/tr|/li)\s*/?>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags((string) $withBreaks), ENT_QUOTES, 'UTF-8'));
    }
}
