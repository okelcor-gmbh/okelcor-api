<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $hidden = [
        'ip_address',
    ];

    protected $casts = [
        'subtotal'          => 'decimal:2',
        'delivery_cost'     => 'decimal:2',
        'discount_amount'   => 'decimal:2',
        'total'             => 'decimal:2',
        'tax_rate'          => 'decimal:2',
        'tax_amount'        => 'decimal:2',
        'is_reverse_charge' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function logs()
    {
        return $this->hasMany(OrderLog::class)->orderBy('created_at');
    }

    public function shipmentEvents()
    {
        return $this->hasMany(OrderShipmentEvent::class)
            ->orderBy('event_date')
            ->orderBy('created_at');
    }

    public function quoteRequest()
    {
        return $this->hasOne(QuoteRequest::class);
    }
}
