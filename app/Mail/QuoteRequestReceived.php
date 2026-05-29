<?php

namespace App\Mail;

use App\Models\QuoteRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteRequestReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly QuoteRequest $quote,
        public readonly bool $isNeedsReview = false,
    ) {}

    public function envelope(): Envelope
    {
        $prefix  = $this->isNeedsReview ? '[Needs Review] ' : '';
        $subject = $prefix . 'New quote request — ' . $this->quote->ref_number;

        return new Envelope(
            subject: $subject,
            replyTo: [
                new Address($this->quote->email, $this->quote->full_name),
            ],
        );
    }

    public function content(): Content
    {
        $adminUrl = rtrim(config('app.url', 'https://api.okelcor.com'), '/');

        return new Content(
            view: 'emails.quote-request-received',
            text: 'emails.quote-request-received-text',
            with: [
                'quote'         => $this->quote,
                'adminUrl'      => $adminUrl,
                'isNeedsReview' => $this->isNeedsReview,
            ],
        );
    }
}
