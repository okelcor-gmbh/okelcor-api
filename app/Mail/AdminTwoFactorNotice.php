<?php

namespace App\Mail;

use App\Models\AdminUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminTwoFactorNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly AdminUser $admin,
        public readonly ?string $graceUntil = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Action required: Enable two-factor authentication for admin access');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-2fa-notice',
            with: [
                'admin'      => $this->admin,
                'graceUntil' => $this->graceUntil,
                'loginUrl'   => rtrim(config('app.frontend_url', 'https://okelcor.com'), '/') . '/admin/security',
            ],
        );
    }
}
