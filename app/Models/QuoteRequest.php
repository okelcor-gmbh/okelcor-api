<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteRequest extends Model
{
    protected $fillable = [
        'customer_id',
        'possible_customer_id',
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
        // Quality / review (CRM-2)
        'quality_score',
        'quality_flags',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        // Lead pipeline (CRM-3)
        'assigned_to',
        'assigned_at',
        'follow_up_at',
        'follow_up_completed_at',
        'follow_up_completed_by',
        'lead_priority',
        'lead_source',
        'lead_customer_type',
        'qualification_status',
        'qualification_reason',
        'internal_notes',
    ];

    protected $casts = [
        'tyre_items'           => 'array',
        'quality_flags'        => 'array',
        'customer_accepted_at' => 'datetime',
        'reviewed_at'          => 'datetime',
        'assigned_at'             => 'datetime',
        'follow_up_at'            => 'datetime',
        'follow_up_completed_at'  => 'datetime',
        'quality_score'        => 'integer',
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

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(\App\Models\AdminUser::class, 'assigned_to');
    }

    public function communications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerCommunication::class)->orderByDesc('created_at');
    }
}
