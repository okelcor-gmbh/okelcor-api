<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\EuDeclarationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    /**
     * Create (or return the existing) invoice for a paid order and generate its PDF.
     * Safe to call multiple times — idempotent on both the DB record and the PDF.
     */
    public function createForOrder(Order $order): ?Invoice
    {
        try {
            $customer = Customer::where('email', $order->customer_email)->first();

            if (! $customer) {
                Log::info('Invoice skipped: no customer account for order', [
                    'ref'   => $order->ref,
                    'email' => $order->customer_email,
                ]);
                return null;
            }

            $invoice = Invoice::where('order_ref', $order->ref)->first();

            if ($invoice) {
                // Repair stale customer_id when the invoice was created before
                // the correct Customer account existed.
                if ((int) $invoice->customer_id !== (int) $customer->id) {
                    $invoice->updateQuietly(['customer_id' => $customer->id]);
                    $invoice->customer_id = $customer->id;
                }

                if ($invoice->pdf_url) {
                    return $invoice;
                }
            }

            if (! $invoice) {
                $invoice = DB::transaction(function () use ($customer, $order) {
                    $year   = now()->year;
                    $prefix = "INV-{$year}-";

                    $lastNumber = Invoice::where('invoice_number', 'like', "{$prefix}%")
                        ->lockForUpdate()
                        ->orderByDesc('invoice_number')
                        ->value('invoice_number');

                    $sequence      = $lastNumber ? (int) substr($lastNumber, strlen($prefix)) + 1 : 1;
                    $invoiceNumber = $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);

                    // Reverse-charge invoices are held until the EU Entry Certificate is
                    // signed. released_at = null keeps them invisible to the customer.
                    $releasedAt = $order->is_reverse_charge ? null : now();

                    return Invoice::create([
                        'customer_id'       => $customer->id,
                        'invoice_number'    => $invoiceNumber,
                        'issued_at'         => now(),
                        'due_at'            => null,
                        'amount'            => $order->total,
                        'status'            => 'paid',
                        'pdf_url'           => null,
                        'released_at'       => $releasedAt,
                        'order_ref'         => $order->ref,
                        'subtotal_net'      => (float) $order->subtotal + (float) $order->delivery_cost,
                        'tax_treatment'     => $order->tax_treatment,
                        'tax_rate'          => $order->tax_rate,
                        'tax_amount'        => $order->tax_amount,
                        'is_reverse_charge' => $order->is_reverse_charge,
                        'promo_code'        => $order->promo_code,
                        'discount_amount'   => (float) $order->discount_amount > 0 ? $order->discount_amount : null,
                        'discount_label'    => $order->discount_label,
                    ]);
                });

                Log::info('Invoice created for order', [
                    'ref'            => $order->ref,
                    'invoice_number' => $invoice->invoice_number,
                ]);
            }

            // Generate PDF for new invoices or existing ones where PDF is missing.
            // Single source of truth — see ensurePdf().
            $this->ensurePdf($invoice, $order);

            // Non-blocking: create EU entry certificate for reverse-charge orders
            try {
                $declarationService = app(EuDeclarationService::class);
                if ($declarationService->shouldRequireForOrder($order)) {
                    $declarationService->createForOrder($order, $invoice);
                    Log::info('EU declaration created for reverse-charge order', [
                        'order_ref'      => $order->ref,
                        'invoice_number' => $invoice->invoice_number,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('EU declaration creation failed for order', [
                    'order_ref' => $order->ref,
                    'error'     => $e->getMessage(),
                ]);
            }

            return $invoice;
        } catch (\Throwable $e) {
            Log::warning('Invoice creation failed for order', [
                'ref'   => $order->ref,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Ensure an invoice has a downloadable PDF on the public disk, self-healing
     * any of the failure modes that previously left customers permanently unable
     * to download (PDF generation failed at creation → pdf_url=null, or pdf_url
     * pointing at a missing/wrong path while the file is physically present).
     *
     * Resolution order:
     *   1. pdf_url is set AND the file exists → use it (fast path).
     *   2. The canonical file (invoices/{number}.pdf) exists → adopt + repair pdf_url.
     *   3. Regenerate from the linked order, store at the canonical path, repair pdf_url.
     *
     * @return string|null  relative public-disk path, or null if it could not be produced.
     */
    public function ensurePdf(Invoice $invoice, ?Order $order = null): ?string
    {
        // 1. Fast path — stored path resolves to a real file.
        if ($invoice->pdf_url) {
            $rel = $this->normalizePdfPath($invoice->pdf_url);
            if (Storage::disk('public')->exists($rel)) {
                return $rel;
            }
        }

        $canonical = "invoices/{$invoice->invoice_number}.pdf";

        // 2. File is physically present at the canonical path — adopt it.
        if (Storage::disk('public')->exists($canonical)) {
            if ($invoice->pdf_url !== $canonical) {
                $invoice->updateQuietly(['pdf_url' => $canonical]);
            }
            return $canonical;
        }

        // 3. Regenerate from the order.
        $order ??= $invoice->order_ref
            ? Order::with('items')->where('ref', $invoice->order_ref)->first()
            : null;

        if (! $order) {
            Log::warning('ensurePdf: cannot regenerate, order not found', [
                'invoice'   => $invoice->invoice_number,
                'order_ref' => $invoice->order_ref,
            ]);
            return null;
        }

        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
                'invoice' => $invoice,
                'order'   => $order,
            ]);

            Storage::disk('public')->put($canonical, $pdf->output());
            $invoice->update(['pdf_url' => $canonical]);

            Log::info('Invoice PDF generated', [
                'invoice' => $invoice->invoice_number,
                'path'    => $canonical,
            ]);

            return $canonical;
        } catch (\Throwable $e) {
            Log::warning('Invoice PDF generation failed', [
                'invoice' => $invoice->invoice_number,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Normalize any stored pdf_url format to a relative public-disk path.
     *   invoices/INV-….pdf                          → invoices/INV-….pdf
     *   /storage/invoices/INV-….pdf                 → invoices/INV-….pdf
     *   https://api.okelcor.com/storage/invoices/…  → invoices/INV-….pdf
     */
    public function normalizePdfPath(string $raw): string
    {
        if (preg_match('#/storage/(.+)$#', $raw, $m)) {
            return $m[1];
        }

        return ltrim($raw, '/');
    }
}
