<?php

namespace App\Mail;

use App\Models\AdminUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Outlook-style compose/reply — a free-form message from a staff member to a
 * customer, distinct from every other Mailable in this app (which render a
 * fixed template for a specific system event). The body is admin-authored
 * rich HTML, already sanitized by RichEmailHtmlSanitizer before this class
 * ever sees it — sanitize-once-at-write, trust-at-render.
 *
 * @param array<int, string> $cc
 * @param array<int, array{path:string, name:string, mime:string}> $attachmentFiles
 */
class CustomerAdHocEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly AdminUser $sender,
        public readonly string $subjectLine,
        public readonly string $bodyHtml,
        public readonly array $cc,
        public readonly array $attachmentFiles,
        public readonly string $messageId,
        public readonly ?string $inReplyTo = null,
    ) {}

    public function envelope(): Envelope
    {
        $senderName = trim($this->sender->display_name ?: $this->sender->name);

        return new Envelope(
            from: new Address(
                config('mail.from.address', 'support@okelcor.com'),
                $senderName . ' (Okelcor)',
            ),
            // Replies go straight back to the staff member who sent it, not
            // a shared inbox — matches ProposalEmail's existing convention.
            replyTo: [$this->sender->email],
            cc: $this->cc,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        // Read fresh at render time — never bake a copy of the signature
        // into the draft, so a signature update takes effect on the very
        // next e-mail without anyone having to do anything.
        $signatureHtml = $this->sender->fresh()?->email_signature;

        return new Content(
            view: 'emails.customer-adhoc',
            text: 'emails.customer-adhoc-text',
            with: [
                'bodyHtml'      => $this->bodyHtml,
                'bodyText'      => $this->toPlainText($this->bodyHtml),
                'signatureHtml' => $signatureHtml,
                'signatureText' => $signatureHtml ? $this->toPlainText($signatureHtml) : null,
                'senderName'    => trim($this->sender->display_name ?: $this->sender->name),
            ],
        );
    }

    public function headers(): Headers
    {
        // messageId/inReplyTo are stored bare (no angle brackets) — Laravel's
        // addIdHeader() wraps messageId itself, but a raw text header like
        // In-Reply-To needs the brackets added explicitly here.
        return new Headers(
            messageId: $this->messageId,
            references: $this->inReplyTo ? [$this->inReplyTo] : [],
            text: $this->inReplyTo ? ['In-Reply-To' => "<{$this->inReplyTo}>"] : [],
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
