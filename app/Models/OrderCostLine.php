<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cost against an order: a supplier's invoice, or a fee the channel took
 * on the way through (Stripe, eBay, the bank). The other half of the
 * profitability calculation OrderFinanceRecord anchors.
 */
class OrderCostLine extends Model
{
    public const KIND_SUPPLIER_INVOICE = 'supplier_invoice';
    public const KIND_FEE              = 'fee';

    public const KINDS = [
        self::KIND_SUPPLIER_INVOICE,
        self::KIND_FEE,
    ];

    /**
     * Validated in the controller, stored as a plain string — an ENUM here
     * would be the order_logs.action trap again. 'other' exists so a fee that
     * fits no bucket is recorded rather than shoehorned into a wrong one.
     */
    public const FEE_CATEGORIES = ['stripe', 'ebay', 'bank', 'shipping', 'other'];

    protected $fillable = [
        'order_id',
        'order_ref',
        'kind',
        'category',
        'supplier',
        'reference',
        'amount',
        'currency',
        'incurred_on',
        'notes',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'uploaded_at',
        'entered_by',
    ];

    protected $hidden = [
        'file_path',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'incurred_on' => 'date',
        'uploaded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'entered_by');
    }

    public function hasFile(): bool
    {
        return $this->getRawOriginal('file_path') !== null;
    }
}
