<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\TradeDocument;
use App\Services\PaymentMilestoneEmailService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TradeDocumentService
{
    private const PREFIXES = [
        'order_confirmation' => 'AB',
        'proforma'           => 'PI',
        'commercial_invoice' => 'CI',
        'packing_list'       => 'PL',
        'delivery_note'      => 'DN',
        'proposal'           => 'QT',
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
     * Idempotent — returns an existing issued order confirmation if one already
     * exists for this order. Otherwise generates number, renders PDF, persists record.
     *
     * Generated automatically when a quote (AN-) is converted to an order.
     * The AB number follows the sequence AB-YYYY-XXXX.
     */
    public function generateOrderConfirmationForOrder(Order $order, ?AdminUser $admin = null): TradeDocument
    {
        $existing = TradeDocument::where('order_id', $order->id)
            ->where('type', 'order_confirmation')
            ->where('status', 'issued')
            ->first();

        if ($existing) {
            return $existing;
        }

        $order->loadMissing('items');
        $quote = QuoteRequest::where('order_id', $order->id)->first();

        $document = DB::transaction(function () use ($order, $admin) {
            return TradeDocument::create([
                'order_id'  => $order->id,
                'order_ref' => $order->ref,
                'type'      => 'order_confirmation',
                'number'    => $this->sequentialNumber('order_confirmation'),
                'status'    => 'issued',
                'issued_by' => $admin?->id,
                'issued_at' => now(),
            ]);
        });

        try {
            $pdfContent = Pdf::loadView('pdf.order-confirmation', [
                'document' => $document,
                'order'    => $order,
                'quote'    => $quote,
            ])->output();

            $pdfPath = 'trade-documents/order-confirmation/' . $document->number . '.pdf';
            Storage::disk('local')->put($pdfPath, $pdfContent);
            $document->update(['pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            Log::warning('Order confirmation PDF generation failed', [
                'document_id' => $document->id,
                'number'      => $document->number,
                'order_ref'   => $order->ref,
                'error'       => $e->getMessage(),
            ]);
        }

        // Lock financials — prevents direct fee/price edits once AB is issued
        $this->lockOrderFinancials($order, $admin, 'Order Confirmation issued');

        Log::info('Order confirmation generated', [
            'number'    => $document->number,
            'order_ref' => $order->ref,
        ]);

        return $document;
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

        // Strengthen lock — proforma is a stronger financial commitment than AB
        $this->lockOrderFinancials($order, $admin, 'Proforma Invoice issued');

        // Set payment milestones on first PI generation, then notify customer
        $this->setDepositMilestones($order);

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
     * Idempotent — returns an existing issued commercial invoice if one already
     * exists for this order. Otherwise generates number, renders PDF, persists record.
     */
    public function generateCommercialInvoiceForOrder(Order $order, ?AdminUser $admin = null): TradeDocument
    {
        $existing = TradeDocument::where('order_id', $order->id)
            ->where('type', 'commercial_invoice')
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
            $number = $this->sequentialNumber('commercial_invoice');
            return TradeDocument::create([
                'order_id'          => $order->id,
                'order_ref'         => $order->ref,
                'type'              => 'commercial_invoice',
                'number'            => $number,
                'status'            => 'issued',
                'original_filename' => $number . '.pdf',
                'issued_by'         => $admin?->id,
                'issued_at'         => now(),
            ]);
        });

        try {
            $pdfContent = Pdf::loadView('pdf.commercial-invoice', [
                'document' => $document,
                'order'    => $order,
                'quote'    => $quote,
                'invoice'  => $invoice,
            ])->output();

            $pdfPath = 'trade-documents/commercial-invoice/' . $document->number . '.pdf';
            Storage::disk('local')->put($pdfPath, $pdfContent);
            $document->update(['pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            Log::warning('Commercial invoice PDF generation failed', [
                'document_id' => $document->id,
                'number'      => $document->number,
                'order_ref'   => $order->ref,
                'error'       => $e->getMessage(),
            ]);
        }

        Log::info('Commercial invoice generated', [
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

    // ── Proposal (CRM-7) ─────────────────────────────────────────────────────

    /**
     * Generate the next sequential proposal number (QT-YYYY-XXXX).
     * Uses a separate counter against quote_requests.proposal_number so proposal
     * numbers are independent of trade_documents sequences.
     */
    public function generateProposalNumber(): string
    {
        return DB::transaction(function () {
            $year = now()->year;
            $base = "QT-{$year}-";

            $last = \App\Models\QuoteRequest::where('proposal_number', 'like', "{$base}%")
                ->lockForUpdate()
                ->orderByDesc('proposal_number')
                ->value('proposal_number');

            $seq = $last ? (int) substr($last, strlen($base)) + 1 : 1;

            return $base . str_pad($seq, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Generate and store the proposal PDF for a quote request.
     * Stores to proposals/QT-YYYY-XXXX.pdf on the local (private) disk.
     * Returns the relative path, or null on failure (non-blocking).
     */
    public function generateProposalPdf(QuoteRequest $quote): ?string
    {
        try {
            $pdfContent = Pdf::loadView('pdf.proposal', [
                'quote' => $quote,
            ])->output();

            $pdfPath = 'proposals/' . $quote->proposal_number . '.pdf';
            Storage::disk('local')->put($pdfPath, $pdfContent);

            return $pdfPath;
        } catch (\Throwable $e) {
            Log::warning('Proposal PDF generation failed', [
                'quote_ref'       => $quote->ref_number,
                'proposal_number' => $quote->proposal_number,
                'error'           => $e->getMessage(),
            ]);

            return null;
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Lock or strengthen the financial lock on an order.
     * Always updates the reason (PI overwrites AB reason). Preserves original
     * locked_at timestamp so audit trail shows when the first lock occurred.
     */
    private function lockOrderFinancials(Order $order, ?AdminUser $admin, string $reason): void
    {
        try {
            $order->update([
                'financials_locked_at'   => $order->financials_locked_at ?? now(),
                'financials_locked_by'   => $admin?->id,
                'financials_lock_reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to lock order financials', [
                'order_ref' => $order->ref,
                'reason'    => $reason,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate and persist deposit/balance amounts when proforma is first issued.
     * No-op if milestones are already set (idempotent).
     * Sends deposit_requested customer email after advancing the stage.
     */
    private function setDepositMilestones(Order $order): void
    {
        if ($order->payment_stage !== 'pending_proforma') {
            return;
        }

        try {
            $depositPercent = (float) ($order->deposit_percent ?? 50);
            $total          = (float) $order->total;
            $depositAmount  = round($total * $depositPercent / 100, 2);
            $balanceAmount  = round($total - $depositAmount, 2);

            $order->update([
                'payment_stage'  => 'deposit_requested',
                'deposit_amount' => $depositAmount,
                'balance_amount' => $balanceAmount,
            ]);

            // Notify customer that a deposit is now due
            app(PaymentMilestoneEmailService::class)->send($order, 'deposit_requested');
        } catch (\Throwable $e) {
            Log::warning('Failed to set payment milestones on proforma generation', [
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
            ]);
        }
    }

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
