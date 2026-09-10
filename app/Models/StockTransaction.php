<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One booked invoice in the stock ledger — supplier (stock in) or
 * customer (stock out) — with its lines kept verbatim as the audit
 * trail. Plain string type (the enum lesson).
 */
class StockTransaction extends Model
{
    public const TYPE_SUPPLIER = 'supplier';
    public const TYPE_CUSTOMER = 'customer';

    protected $fillable = [
        'type', 'party_name', 'reference', 'total_qty', 'total_amount', 'lines', 'created_by',
    ];

    protected $casts = [
        'total_qty'    => 'integer',
        'total_amount' => 'decimal:2',
        'lines'        => 'array',
    ];
}
