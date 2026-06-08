<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CRM-8 — sent when an admin approves a buyer (approved_buyer / wholesale_buyer).
 */
class ApprovedAccountEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Customer $customer,
        public readonly string $loginUrl,
        public readonly string $supportEmail,
        public readonly bool $requiresEmailVerification = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Okelcor account has been approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-approved',
            text: 'emails.account-approved-text',
            with: [
                'customer'                  => $this->customer,
                'loginUrl'                  => $this->loginUrl,
                'supportEmail'              => $this->supportEmail,
                'requiresEmailVerification' => $this->requiresEmailVerification,
            ],
        );
    }
}
