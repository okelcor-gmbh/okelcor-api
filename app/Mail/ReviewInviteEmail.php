<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReviewInviteEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly string $reviewUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'How did we do? Your Okelcor delivery');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.review-invite',
            with: [
                'order'     => $this->order,
                'reviewUrl' => $this->reviewUrl,
            ],
        );
    }
}
