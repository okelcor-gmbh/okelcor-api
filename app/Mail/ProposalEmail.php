<?php

namespace App\Mail;

use App\Models\AdminUser;
use App\Models\QuoteRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProposalEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly QuoteRequest $quote,
        public readonly string $acceptUrl,
        public readonly ?string $adminMessage = null,
        public readonly ?AdminUser $sender = null,
    ) {}

    public function envelope(): Envelope
    {
        $number  = $this->quote->proposal_number ?? 'QT-' . $this->quote->ref_number;
        $subject = "Proposal from Okelcor — {$number}";

        $replyTo = $this->sender?->email ?? config('mail.from.address');

        return new Envelope(
            subject: $subject,
            replyTo: [$replyTo],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proposal-email',
            text: 'emails.proposal-email-text',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
