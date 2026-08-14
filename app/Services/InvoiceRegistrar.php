<?php

namespace App\Services;

use App\Models\FinanceInvoice;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\TradeDocument;
use Illuminate\Support\Facades\Log;

/**
 * Writes every invoice this system produces into the invoice register, in the
 * same record format finance uses for sevDesk.
 *
 * The reconciliation used to compare two differently-shaped things: our
 * `invoices` table against finance's typed-in rows. Anything that needed
 * translating to be compared is somewhere a mismatch can hide, and it left one
 * category out entirely — a commercial invoice an order manager issues and
 * sends to a customer has no `Invoice` row at all, so it appeared on neither
 * side of the comparison while being, to the customer, an invoice.
 *
 * Registration is driven by model events rather than by call sites. There are
 * five places a trade document is created and more than one that raises an
 * invoice, and a guarantee that depends on every future one remembering to call
 * a registrar is not a guarantee. Same reasoning, and the same shape, as
 * MarketingContact's market-membership sync.
 *
 * Nothing here may fail the thing that triggered it. An invoice that generated
 * correctly must not fail because a reporting row could not be written — the
 * register is downstream of the money, never in front of it.
 */
class InvoiceRegistrar
{
    /** The register entry for something this system produced. */
    public const SYSTEM = 'okelcor';

    /**
     * Trade-document types that are an invoice as far as a customer is
     * concerned. A packing list or a delivery note is not — registering those
     * would inflate our side of the comparison with documents finance would
     * never have raised in sevDesk, which reads as a discrepancy that is not
     * one.
     */
    public const INVOICE_DOCUMENT_TYPES = ['commercial_invoice', 'proforma'];

    public function registerInvoice(Invoice $invoice): ?FinanceInvoice
    {
        if (! $this->available() || ! $invoice->invoice_number) {
            return null;
        }

        $order = $invoice->order_ref ? Order::where('ref', $invoice->order_ref)->first() : null;

        return $this->write([
            'external_number' => $invoice->invoice_number,
            'order_ref'       => $invoice->order_ref,
            'invoice_number'  => $invoice->invoice_number,
            'amount'          => $invoice->amount,
            'currency'        => $order?->currency ?: 'EUR',
            'issued_on'       => ($invoice->issued_at ?? $invoice->created_at ?? now())->toDateString(),
            'channel'         => $order?->channel() ?? Order::CHANNEL_NORMAL,
            'source_type'     => 'invoice',
            'source_id'       => $invoice->id,
            'notes'           => 'Registered automatically from the tax invoice this system raised.',
        ]);
    }

    public function registerTradeDocument(TradeDocument $document): ?FinanceInvoice
    {
        if (! $this->available()
            || ! in_array($document->type, self::INVOICE_DOCUMENT_TYPES, true)
            || ! $document->number) {
            return null;
        }

        $order = $document->order_id ? Order::find($document->order_id) : null;

        return $this->write([
            'external_number' => $document->number,
            'order_ref'       => $document->order_ref,
            // Deliberately null: a proforma or a commercial invoice is not the
            // tax invoice, and putting its number here would match it against
            // a sevDesk row for a different document.
            'invoice_number'  => null,
            'amount'          => $order?->total,
            'currency'        => $order?->currency ?: 'EUR',
            'issued_on'       => ($document->issued_at ?? $document->created_at ?? now())->toDateString(),
            'channel'         => $order?->channel() ?? Order::CHANNEL_NORMAL,
            'source_type'     => 'trade_document',
            'source_id'       => $document->id,
            'notes'           => 'Registered automatically from the '
                . str_replace('_', ' ', $document->type) . ' issued to the customer.',
        ]);
    }

    /**
     * Removes a register row when the thing it described is voided or deleted.
     *
     * A superseded document that stays in the register is counted as an
     * invoice that was never really issued, which is a discrepancy invented by
     * the reporting rather than found by it.
     */
    public function forget(string $sourceType, int $sourceId): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            FinanceInvoice::where('system', self::SYSTEM)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Invoice register row could not be removed', [
                'source' => $sourceType . ':' . $sourceId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): ?FinanceInvoice
    {
        try {
            // Keyed on (system, external_number), which is the table's own
            // unique constraint — so re-issuing or re-saving the same document
            // updates its row rather than adding a second one and reporting
            // one invoice as two.
            return FinanceInvoice::updateOrCreate(
                ['system' => self::SYSTEM, 'external_number' => $attributes['external_number']],
                $attributes
            );
        } catch (\Throwable $e) {
            Log::warning('Invoice could not be written to the register', [
                'number' => $attributes['external_number'] ?? null,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Deploy-order safety. The register is a reporting table; between the code
     * shipping and the migration running, raising an invoice must still work.
     */
    private function available(): bool
    {
        return FinanceInvoice::registerAvailable();
    }
}
