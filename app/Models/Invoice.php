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
        'amount'            => 'decimal:2',
        'subtotal_net'      => 'decimal:2',
        'tax_rate'          => 'decimal:2',
        'tax_amount'        => 'decimal:2',
        'is_reverse_charge' => 'boolean',
        'discount_amount'   => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function euDeclaration(): HasOne
    {
        return $this->hasOne(EuDeclaration::class);
    }
}
