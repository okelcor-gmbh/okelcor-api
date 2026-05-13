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

class TradeDocumentEmail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $documentLabel;
    public readonly string $subjectLine;

    public function __construct(
        public readonly TradeDocument $document,
        public readonly Order $order,
        public readonly string $recipientEmail,
        public readonly ?string $adminMessage = null,
    ) {
        $this->documentLabel = $this->resolveLabel();
        $this->subjectLine   = $this->resolveSubject();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address', 'support@okelcor.com'),
                config('mail.from.name', 'Okelcor'),
            ),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.trade-document-email',
            text: 'emails.trade-document-email-text',
            with: [
                'document'      => $this->document,
                'order'         => $this->order,
                'documentLabel' => $this->documentLabel,
                'adminMessage'  => $this->adminMessage,
            ],
        );
    }

    public function attachments(): array
    {
        $storedPath = $this->document->getRawOriginal('pdf_path')
            ?? $this->document->getRawOriginal('file_path');

        if (! $storedPath) {
            return [];
        }

        $fullPath = storage_path('app/private/' . $storedPath);

        if (! file_exists($fullPath)) {
            return [];
        }

        $filename = $this->document->original_filename
            ?? ($this->document->number ? $this->document->number . '.pdf' : 'document.pdf');

        $mime = $this->document->mime_type ?? 'application/pdf';

        return [
            Attachment::fromPath($fullPath)
                ->as($filename)
                ->withMime($mime),
        ];
    }

    private function resolveLabel(): string
    {
        return match ($this->document->type) {
            'proforma'           => 'Proforma Invoice',
            'commercial_invoice' => 'Commercial Invoice',
            'packing_list'       => 'Packing List',
            'delivery_note'      => 'Delivery Note',
            'shipment_document'  => $this->document->type_label ?? 'Shipment Document',
            default              => 'Document',
        };
    }

    private function resolveSubject(): string
    {
        return match ($this->document->type) {
            'proforma'           => 'Proforma Invoice for Order ' . $this->order->ref,
            'commercial_invoice' => 'Commercial Invoice for Order ' . $this->order->ref,
            'packing_list'       => 'Packing List for Order ' . $this->order->ref,
            'delivery_note'      => 'Delivery Note for Order ' . $this->order->ref,
            'shipment_document'  => ($this->document->type_label ?? 'Shipment Document') . ' for Order ' . $this->order->ref,
            default              => 'Document for Order ' . $this->order->ref,
        };
    }
}
