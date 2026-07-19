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
 * @param array<int, string> $ccRecipients
 * @param array<int, array{path:string, name:string, mime:string}> $attachmentFiles
 */
class CustomerAdHocEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Note: named $ccRecipients, not $cc — Mailable already declares a
     * public, non-readonly $cc property of its own (used internally by the
     * fluent ->cc() builder). Redeclaring a property inherited from the
     * parent as readonly is a PHP fatal error at class-load time, not a
     * catchable exception — same reasoning behind $subjectLine (not
     * $subject) and $attachmentFiles (not $attachments) below.
     */
    public function __construct(
        public readonly AdminUser $sender,
        public readonly string $subjectLine,
        public readonly string $bodyHtml,
        public readonly array $ccRecipients,
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
            replyTo: [$this->buildReplyToAddress()],
            cc: $this->ccRecipients,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        // $bodyHtml already has the sender's signature appended (see
        // AdminCommunicationController::appendSignature) — the same value
        // is also what's stored on the CustomerCommunication record, so
        // the admin panel's own thread view always matches what was
        // actually e-mailed. Plain-text derives from the same single
        // source rather than a separately-rendered signature block, so
        // the two can never drift apart.
        return new Content(
            view: 'emails.customer-adhoc',
            text: 'emails.customer-adhoc-text',
            with: [
                'bodyHtml'   => $this->bodyHtml,
                'bodyText'   => $this->toPlainText($this->bodyHtml),
                'senderName' => trim($this->sender->display_name ?: $this->sender->name),
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

    /**
     * Plus-addressed reply-to (e.g. support+{uuid}@okelcor.com) so an
     * inbound reply can be matched back to this exact message directly —
     * see FetchInboundEmails. Falls back to the sending admin's own address
     * (the previous, only behaviour) when inbound capture isn't configured
     * yet, so this is safe to ship ahead of that setup being finished.
     */
    private function buildReplyToAddress(): string
    {
        $inboundAddress = config('services.mail_inbound.address');

        if (! config('services.mail_inbound.enabled') || ! $inboundAddress || ! str_contains($inboundAddress, '@')) {
            return $this->sender->email;
        }

        [$local, $domain] = explode('@', $inboundAddress, 2);
        $tag = explode('@', $this->messageId)[0];

        return "{$local}+{$tag}@{$domain}";
    }

    private function toPlainText(string $html): string
    {
        $withBreaks = preg_replace('#<(br|/p|/div|/tr|/li)\s*/?>#i', "\n", $html);
        return trim(html_entity_decode(strip_tags((string) $withBreaks), ENT_QUOTES, 'UTF-8'));
    }
}
