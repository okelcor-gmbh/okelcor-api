<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Customer $customer,
        public readonly string $activationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Rendered within the recipient's locale (Customer implements
            // HasLocalePreference), so __() resolves to their language.
            subject: __('emails.invitation.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-invitation',
            text: 'emails.customer-invitation-text',
            with: [
                'customer'      => $this->customer,
                'activationUrl' => $this->activationUrl,
            ],
        );
    }
}
