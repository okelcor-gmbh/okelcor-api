<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteRequest extends Model
{
    protected $fillable = [
        'customer_id',
        'order_id',
        'ref_number',
        'full_name',
        'contact_person',
        'company_name',
        'company_address',
        'company_city',
        'company_postal_code',
        'email',
        'phone',
        'country',
        'business_type',
        'tyre_category',
        'brand_preference',
        'tyre_size',
        'tyre_condition',
        'used_tyre_grade',
        'used_tyre_notes',
        'quantity',
        'tyre_items',
        'budget_range',
        'delivery_location',
        'delivery_timeline',
        'incoterm',
        'incoterm_type',
        'notes',
        'status',
        'admin_notes',
        'ip_address',
        'vat_number',
        'vat_valid',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'attachment_size',
        'delivery_address',
        'delivery_city',
        'delivery_postal_code',
        'customer_acceptance_status',
        'customer_accepted_at',
        'customer_accepted_ip',
        'customer_accepted_user_agent',
        'customer_acceptance_note',
    ];

    protected $casts = [
        'tyre_items'           => 'array',
        'customer_accepted_at' => 'datetime',
    ];

    protected $hidden = [
        'ip_address',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
