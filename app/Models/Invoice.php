<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    protected $fillable = [
        'customer_id',
        'invoice_number',
        'issued_at',
        'due_at',
        'amount',
        'status',
        'pdf_url',
        'released_at',
        'order_ref',
        'subtotal_net',
        'tax_treatment',
        'tax_rate',
        'tax_amount',
        'is_reverse_charge',
        'promo_code',
        'discount_amount',
        'discount_label',
    ];

    protected $casts = [
        'issued_at'         => 'datetime',
        'due_at'            => 'datetime',
        'released_at'       => 'datetime',
        'amount'            => 'decimal:2',
        'subtotal_net'      => 'decimal:2',
        'tax_rate'          => 'decimal:2',
        'tax_amount'        => 'decimal:2',
        'is_reverse_charge' => 'boolean',
        'discount_amount'   => 'decimal:2',
    ];

    /**
     * Every tax invoice this system raises is written into the invoice
     * register, so finance's sevDesk row and ours sit in one table with one
     * shape and can be compared without translating between them.
     *
     * On the model rather than at the call sites: an invoice is created from
     * the Stripe webhook, from the admin mark-paid path and from the
     * self-healing download, and a guarantee that depends on each of them
     * remembering to call a registrar is not a guarantee.
     *
     * Never allowed to fail the invoice — the register is downstream of the
     * money, never in front of it.
     */
    protected static function booted(): void
    {
        static::saved(function (Invoice $invoice) {
            if (! $invoice->wasRecentlyCreated && ! $invoice->wasChanged(['amount', 'issued_at', 'invoice_number'])) {
                return;
            }

            try {
                app(\App\Services\InvoiceRegistrar::class)->registerInvoice($invoice);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Invoice register hook failed', [
                    'invoice' => $invoice->invoice_number,
                    'error'   => $e->getMessage(),
                ]);
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function euDeclaration(): HasOne
    {
        return $this->hasOne(EuDeclaration::class);
    }
}
