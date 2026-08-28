<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cost on an order that arrives without an invoice — an eBay fee, Stripe's
 * processing cut, a bank charge. Together with the supplier invoices in the
 * register these are the cost side of the order's profitability.
 */
class OrderCost extends Model
{
    /** Keyed values with the labels the panel and the CSV export use. */
    public const TYPES = [
        'ebay_fee'   => 'eBay fees',
        'stripe_fee' => 'Stripe fees',
        'bank_cost'  => 'Bank charges',
        'shipping'   => 'Shipping / freight',
        'customs'    => 'Customs & duties',
        'other'      => 'Other cost',
    ];

    protected $fillable = [
        'order_id',
        'order_ref',
        'type',
        'label',
        'amount',
        'currency',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recorded_by');
    }
}
