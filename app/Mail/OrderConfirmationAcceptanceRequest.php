<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\TradeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationAcceptanceRequest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly TradeDocument $document,
        public readonly string $acceptUrl,
        public readonly string $expiresAt,
        public readonly ?string $adminMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address', 'support@okelcor.com'),
                config('mail.from.name', 'Okelcor'),
            ),
            subject: 'Action required: Please confirm your Order — ' . $this->order->ref,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.order-confirmation-acceptance-request',
            text: 'emails.order-confirmation-acceptance-request-text',
            with: [
                'order'        => $this->order,
                'document'     => $this->document,
                'acceptUrl'    => $this->acceptUrl,
                'expiresAt'    => $this->expiresAt,
                'adminMessage' => $this->adminMessage,
            ],
        );
    }

    public function attachments(): array
    {
        $storedPath = $this->document->getRawOriginal('pdf_path');

        if (! $storedPath) {
            return [];
        }

        $fullPath = storage_path('app/private/' . $storedPath);

        if (! file_exists($fullPath)) {
            return [];
        }

        $filename = $this->document->original_filename
            ?? ($this->document->number ? $this->document->number . '.pdf' : 'order-confirmation.pdf');

        return [
            Attachment::fromPath($fullPath)
                ->as($filename)
                ->withMime('application/pdf'),
        ];
    }
}
