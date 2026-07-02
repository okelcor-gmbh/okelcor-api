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
        'source',
        'ebay_order_id',
        'ebay_order_status',
        'ebay_payment_status',
        'ebay_fulfillment_status',
        'ebay_buyer_username',
        'ebay_last_synced_at',
        'ebay_raw_summary',
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
        'tracking_device_id',
        'dest_lat',
        'dest_lon',
        'route_total_km',
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
        'customer_acceptance_status',
        'customer_accepted_at',
        'customer_accepted_ip',
        'customer_accepted_user_agent',
        'customer_acceptance_note',
        'acceptance_token',
        'acceptance_token_expires_at',
        'payment_stage',
        'deposit_percent',
        'deposit_amount',
        'deposit_paid_at',
        'deposit_confirmed_by',
        'balance_amount',
        'balance_paid_at',
        'balance_confirmed_by',
        'shipment_released_at',
        'shipment_released_by',
        'shipment_release_note',
        'deposit_requested_email_sent_at',
        'deposit_paid_email_sent_at',
        'balance_due_email_sent_at',
        'balance_paid_email_sent_at',
        'shipment_released_email_sent_at',
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
        'customer_accepted_at'              => 'datetime',
        'acceptance_token_expires_at'       => 'datetime',
        'deposit_percent'                   => 'decimal:2',
        'deposit_amount'                    => 'decimal:2',
        'deposit_paid_at'                   => 'datetime',
        'balance_amount'                    => 'decimal:2',
        'balance_paid_at'                   => 'datetime',
        'shipment_released_at'                  => 'datetime',
        'deposit_requested_email_sent_at'       => 'datetime',
        'deposit_paid_email_sent_at'            => 'datetime',
        'balance_due_email_sent_at'             => 'datetime',
        'balance_paid_email_sent_at'            => 'datetime',
        'shipment_released_email_sent_at'       => 'datetime',
        'ebay_last_synced_at'                   => 'datetime',
        'ebay_raw_summary'                      => 'array',
    ];

    public function isFinancialsLocked(): bool
    {
        return $this->financials_locked_at !== null;
    }

    /**
     * True once the customer owes nothing further — either a non-milestone
     * order paid in full, or a milestone order that reached balance_paid.
     */
    public function isFullyPaid(): bool
    {
        return $this->payment_status === 'paid'
            || in_array($this->payment_stage, ['balance_paid', 'shipment_released'], true);
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

    /**
     * The tax invoice for this order. Invoices link to orders by ref string
     * (order_ref), not a numeric FK, so the relation is keyed accordingly.
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_ref', 'ref');
    }

    public function financialsLockedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'financials_locked_by');
    }

    public function financialsRevisionRequestedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'financials_revision_requested_by');
    }

    public function depositConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'deposit_confirmed_by');
    }

    public function balanceConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'balance_confirmed_by');
    }

    public function shipmentReleasedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'shipment_released_by');
    }
}
