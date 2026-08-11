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
        'currency',
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
     * Re-derives `subtotal` from the line items and carries that through to
     * `total`, preserving whatever non-line component the total already had.
     *
     * The line items are the source of truth — not a delta applied on top of
     * whatever subtotal happened to be stored. That distinction is the whole
     * point: an order created without items keeps a placeholder subtotal equal
     * to the total typed in by hand (see AdminOrderController::store, where
     * `subtotal` falls back to `total`). Adding a line to such an order under
     * a delta model added the line on top of the placeholder and counted the
     * same money twice — a €15,000 order displayed a €30,000 total.
     *
     * Deliberately a no-op for an order with no items: there the stored total
     * is the only record of what the order is worth, and recomputing would
     * zero it.
     *
     * @return array{subtotal_from: float, subtotal_to: float, total_from: float, total_to: float, changed: bool}
     */
    public function recalculateTotalsFromItems(): array
    {
        $subtotalFrom = round((float) $this->subtotal, 2);
        $totalFrom    = round((float) $this->total, 2);

        $unchanged = [
            'subtotal_from' => $subtotalFrom, 'subtotal_to' => $subtotalFrom,
            'total_from'    => $totalFrom,    'total_to'    => $totalFrom,
            'changed'       => false,
        ];

        if ($this->items()->count() === 0) {
            return $unchanged;
        }

        // Everything in the total the line items do not explain: delivery,
        // tax, discount, and for imported orders whatever the source system
        // folded in. Carried across rather than rebuilt from columns — the
        // relationship between those columns and `total` is not consistent
        // across every order source (website, eBay, Wix, manual).
        $extras = round($totalFrom - $subtotalFrom, 2);

        $subtotalTo = round((float) $this->items()->sum('line_total'), 2);
        $totalTo    = round($subtotalTo + $extras, 2);

        if ($subtotalTo === $subtotalFrom && $totalTo === $totalFrom) {
            return $unchanged;
        }

        $this->update(['subtotal' => $subtotalTo, 'total' => $totalTo]);

        return [
            'subtotal_from' => $subtotalFrom, 'subtotal_to' => $subtotalTo,
            'total_from'    => $totalFrom,    'total_to'    => $totalTo,
            'changed'       => true,
        ];
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

    /**
     * True once someone has actually asked the customer for a deposit.
     *
     * 'pending_proforma' is the resting state of every order, so it is not a
     * milestone — it means the ladder has not been started. The customer
     * portal must not render a deposit/balance schedule before this is true:
     * a buyer seeing "Deposit Requested — 50%" for money nobody has asked him
     * for reads it as a demand, and reasonably queries it.
     */
    public function paymentMilestonesActive(): bool
    {
        return $this->payment_stage !== null
            && $this->payment_stage !== 'pending_proforma';
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
