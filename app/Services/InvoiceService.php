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

            if ($invoice && $invoice->pdf_url) {
                return $invoice;
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

            // Generate PDF for new invoices or existing ones where PDF is missing
            try {
                $pdf  = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
                    'invoice' => $invoice,
                    'order'   => $order,
                ]);

                $path = "invoices/{$invoice->invoice_number}.pdf";
                Storage::disk('public')->put($path, $pdf->output());
                $invoice->update(['pdf_url' => $path]);

                Log::info('Invoice PDF generated', [
                    'invoice' => $invoice->invoice_number,
                    'path'    => $path,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Invoice PDF generation failed', [
                    'invoice' => $invoice->invoice_number,
                    'error'   => $e->getMessage(),
                ]);
            }

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
}
