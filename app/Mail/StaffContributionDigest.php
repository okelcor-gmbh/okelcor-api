<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The periodic team contribution report.
 *
 * Carries its own caveats in the body rather than leaving them on the screen
 * the numbers came from — this is an e-mail, it gets forwarded, and by the
 * third forward nobody remembers what the column meant.
 */
class StaffContributionDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public readonly array $report,
        public readonly string $emailSubject,
        public readonly ?string $panelUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-contribution-digest',
            text: 'emails.staff-contribution-digest-text',
            with: [
                'report'   => $this->report,
                'panelUrl' => $this->panelUrl,
            ],
        );
    }
}
