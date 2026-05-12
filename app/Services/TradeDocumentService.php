<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\TradeDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TradeDocumentService
{
    private const PREFIXES = [
        'proforma'           => 'PI',
        'commercial_invoice' => 'CI',
        'packing_list'       => 'PL',
        'delivery_note'      => 'DN',
    ];

    /**
     * Generate the next sequential document number for the given type.
     * Safe to call standalone — wraps its own transaction.
     */
    public function generateNumber(string $type): string
    {
        return DB::transaction(fn () => $this->sequentialNumber($type));
    }

    /**
     * Idempotent — returns an existing issued proforma if one already exists
     * for this order. Otherwise generates number, renders PDF, persists record.
     *
     * PDF is generated outside the DB transaction so a render failure does not
     * roll back the document record. The record is returned even if PDF fails.
     */
    public function generateProformaForOrder(Order $order, ?AdminUser $admin = null): TradeDocument
    {
        $existing = TradeDocument::where('order_id', $order->id)
            ->where('type', 'proforma')
            ->where('status', 'issued')
            ->first();

        if ($existing) {
            return $existing;
        }

        $order->loadMissing('items');
        $quote = QuoteRequest::where('order_id', $order->id)->first();

        // Create DB record first (sequential number is locked inside transaction)
        $document = DB::transaction(function () use ($order, $admin) {
            return TradeDocument::create([
                'order_id'  => $order->id,
                'order_ref' => $order->ref,
                'type'      => 'proforma',
                'number'    => $this->sequentialNumber('proforma'),
                'status'    => 'issued',
                'issued_by' => $admin?->id,
                'issued_at' => now(),
            ]);
        });

        // Generate and store PDF — non-blocking
        try {
            $pdfContent = Pdf::loadView('pdf.proforma-invoice', [
                'document' => $document,
                'order'    => $order,
                'quote'    => $quote,
            ])->output();

            $pdfPath = 'trade-documents/proforma/' . $document->number . '.pdf';
            Storage::disk('local')->put($pdfPath, $pdfContent);
            $document->update(['pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            Log::warning('Proforma PDF generation failed', [
                'document_id' => $document->id,
                'number'      => $document->number,
                'order_ref'   => $order->ref,
                'error'       => $e->getMessage(),
            ]);
        }

        Log::info('Proforma invoice generated', [
            'number'    => $document->number,
            'order_ref' => $order->ref,
        ]);

        return $document;
    }

    /**
     * Idempotent — returns an existing issued packing list if one already exists
     * for this order. Otherwise generates number, renders PDF, persists record.
     */
    public function generatePackingListForOrder(Order $order, ?AdminUser $admin = null): TradeDocument
    {
        $existing = TradeDocument::where('order_id', $order->id)
            ->where('type', 'packing_list')
            ->where('status', 'issued')
            ->first();

        if ($existing) {
            return $existing;
        }

        $order->loadMissing('items', 'shipmentEvents');

        // Eagerly load the product relation on each item for tyre spec fields
        $order->items->each(fn ($item) => $item->loadMissing('product'));

        $quote   = QuoteRequest::where('order_id', $order->id)->first();
        $invoice = Invoice::where('order_ref', $order->ref)->first();

        $document = DB::transaction(function () use ($order, $admin) {
            $number = $this->sequentialNumber('packing_list');
            return TradeDocument::create([
                'order_id'          => $order->id,
                'order_ref'         => $order->ref,
                'type'              => 'packing_list',
                'number'            => $number,
                'status'            => 'issued',
                'original_filename' => $number . '.pdf',
                'issued_by'         => $admin?->id,
                'issued_at'         => now(),
            ]);
        });

        try {
            $pdfContent = Pdf::loadView('pdf.packing-list', [
                'document' => $document,
                'order'    => $order,
                'quote'    => $quote,
                'invoice'  => $invoice,
            ])->output();

            $pdfPath = 'trade-documents/packing-list/' . $document->number . '.pdf';
            Storage::disk('local')->put($pdfPath, $pdfContent);
            $document->update(['pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            Log::warning('Packing list PDF generation failed', [
                'document_id' => $document->id,
                'number'      => $document->number,
                'order_ref'   => $order->ref,
                'error'       => $e->getMessage(),
            ]);
        }

        Log::info('Packing list generated', [
            'number'    => $document->number,
            'order_ref' => $order->ref,
        ]);

        return $document;
    }

    /**
     * Idempotent — returns an existing issued delivery note if one already exists
     * for this order. Otherwise generates number, renders PDF, persists record.
     */
    public function generateDeliveryNoteForOrder(Order $order, ?AdminUser $admin = null): TradeDocument
    {
        $existing = TradeDocument::where('order_id', $order->id)
            ->where('type', 'delivery_note')
            ->where('status', 'issued')
            ->first();

        if ($existing) {
            return $existing;
        }

        $order->loadMissing('items', 'shipmentEvents');
        $order->items->each(fn ($item) => $item->loadMissing('product'));

        $quote   = QuoteRequest::where('order_id', $order->id)->first();
        $invoice = Invoice::where('order_ref', $order->ref)->first();

        $document = DB::transaction(function () use ($order, $admin) {
            $number = $this->sequentialNumber('delivery_note');
            return TradeDocument::create([
                'order_id'          => $order->id,
                'order_ref'         => $order->ref,
                'type'              => 'delivery_note',
                'number'            => $number,
                'status'            => 'issued',
                'original_filename' => $number . '.pdf',
                'issued_by'         => $admin?->id,
                'issued_at'         => now(),
            ]);
        });

        try {
            $pdfContent = Pdf::loadView('pdf.delivery-note', [
                'document' => $document,
                'order'    => $order,
                'quote'    => $quote,
                'invoice'  => $invoice,
            ])->output();

            $pdfPath = 'trade-documents/delivery-note/' . $document->number . '.pdf';
            Storage::disk('local')->put($pdfPath, $pdfContent);
            $document->update(['pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            Log::warning('Delivery note PDF generation failed', [
                'document_id' => $document->id,
                'number'      => $document->number,
                'order_ref'   => $order->ref,
                'error'       => $e->getMessage(),
            ]);
        }

        Log::info('Delivery note generated', [
            'number'    => $document->number,
            'order_ref' => $order->ref,
        ]);

        return $document;
    }

    // -------------------------------------------------------------------------

    /** Must be called inside an active DB transaction. */
    private function sequentialNumber(string $type): string
    {
        $prefix = self::PREFIXES[$type] ?? strtoupper($type);
        $year   = now()->year;
        $base   = "{$prefix}-{$year}-";

        $last = TradeDocument::where('number', 'like', "{$base}%")
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $seq = $last ? (int) substr($last, strlen($base)) + 1 : 1;

        return $base . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
