<?php

namespace App\Mail;

use App\Models\QuoteRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteRequestAcknowledgement extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly QuoteRequest $quote,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'We received your quote request — ' . $this->quote->ref_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quote-request-acknowledgement',
            text: 'emails.quote-request-acknowledgement-text',
            with: [
                'quote' => $this->quote,
            ],
        );
    }
}
