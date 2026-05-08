<?php

namespace App\Services;

use App\Models\AdminUser;
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
