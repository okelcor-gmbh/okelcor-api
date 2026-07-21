<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BulkCampaignEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bulk-campaign',
            with: [
                'bodyHtml'       => $this->bodyHtml,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                // A campaign body that's already a full, self-contained
                // HTML document (its own <html>/<head>/<body> — e.g. a
                // designed template pasted in whole) must render as-is:
                // nesting it inside this view's own <html><body> would be
                // invalid HTML and unpredictable across mail clients. A
                // plain snippet (the original, simpler use case) still
                // gets wrapped with the standard footer below.
                'isFullDocument' => (bool) preg_match('/^\s*(<!doctype\s+html|<html)/i', $this->bodyHtml),
            ],
        );
    }
}
