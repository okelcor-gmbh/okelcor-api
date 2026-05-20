<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'ref',
        'customer_name',
        'customer_email',
        'customer_phone',
        'address',
        'city',
        'postal_code',
        'country',
        'payment_method',
        'subtotal',
        'delivery_cost',
        'total',
        'status',
        'payment_status',
        'mode',
        'admin_notes',
        'promo_code',
        'discount_amount',
        'discount_label',
        'ip_address',
        'vat_number',
        'vat_valid',
        'tax_treatment',
        'tax_rate',
        'tax_amount',
        'is_reverse_charge',
        'payment_session_id',
        'carrier',
        'carrier_type',
        'tracking_number',
        'container_number',
        'tracking_status',
        'estimated_delivery',
        'eta',
        'financials_locked_at',
        'financials_locked_by',
        'financials_lock_reason',
        'financials_revision_required',
        'financials_revision_reason',
        'financials_revision_requested_by',
        'financials_revision_requested_at',
        'financials_revision_changes',
    ];

    protected $hidden = [
        'ip_address',
    ];

    protected $casts = [
        'subtotal'                          => 'decimal:2',
        'delivery_cost'                     => 'decimal:2',
        'discount_amount'                   => 'decimal:2',
        'total'                             => 'decimal:2',
        'tax_rate'                          => 'decimal:2',
        'tax_amount'                        => 'decimal:2',
        'is_reverse_charge'                 => 'boolean',
        'financials_locked_at'              => 'datetime',
        'financials_revision_required'      => 'boolean',
        'financials_revision_requested_at'  => 'datetime',
        'financials_revision_changes'       => 'array',
    ];

    public function isFinancialsLocked(): bool
    {
        return $this->financials_locked_at !== null;
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class)->orderBy('created_at');
    }

    public function shipmentEvents(): HasMany
    {
        return $this->hasMany(OrderShipmentEvent::class)
            ->orderBy('event_date')
            ->orderBy('created_at');
    }

    public function quoteRequest(): HasOne
    {
        return $this->hasOne(QuoteRequest::class);
    }

    public function euDeclaration(): HasOne
    {
        return $this->hasOne(EuDeclaration::class);
    }

    public function tradeDocuments(): HasMany
    {
        return $this->hasMany(TradeDocument::class)->orderByDesc('created_at');
    }

    public function financialsLockedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'financials_locked_by');
    }

    public function financialsRevisionRequestedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'financials_revision_requested_by');
    }
}
