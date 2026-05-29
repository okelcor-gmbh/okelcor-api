<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CrmFollowUpEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $emailSubject,  // 'subject' conflicts with Mailable::$subject (non-readonly parent property)
        public readonly string $body,
        public readonly string $ref,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.crm-follow-up',
            text: 'emails.crm-follow-up-text',
            with: [
                'recipientName' => $this->recipientName,
                'body'          => $this->body,
                'ref'           => $this->ref,
            ],
        );
    }
}
