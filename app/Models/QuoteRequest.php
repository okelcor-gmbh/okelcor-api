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
        'lead_metadata',
        'lead_customer_type',
        'qualification_status',
        'qualification_reason',
        'internal_notes',
        // Proposal lifecycle (CRM-7)
        'proposal_status',
        'proposal_number',
        'proposal_items',
        'proposal_total',
        'proposal_currency',
        'proposal_acceptance_token',
        'proposal_sent_at',
        'proposal_accepted_at',
        'proposal_rejected_at',
        'proposal_expires_at',
        'proposal_voided_at',
        'proposal_voided_by',
        'proposal_void_reason',
        'proposal_rejection_reason',
        'proposal_pdf_path',
        'proposal_accepted_ip',
        'proposal_accepted_user_agent',
        'proposal_acceptance_note',
    ];

    protected $casts = [
        'tyre_items'               => 'array',
        'quality_flags'            => 'array',
        'lead_metadata'            => 'array',
        'proposal_items'           => 'array',
        'proposal_total'           => 'decimal:2',
        'customer_accepted_at'     => 'datetime',
        'reviewed_at'              => 'datetime',
        'assigned_at'              => 'datetime',
        'follow_up_at'             => 'datetime',
        'follow_up_completed_at'   => 'datetime',
        'quality_score'            => 'integer',
        // Proposal timestamps (CRM-7)
        'proposal_sent_at'         => 'datetime',
        'proposal_accepted_at'     => 'datetime',
        'proposal_rejected_at'     => 'datetime',
        'proposal_expires_at'      => 'datetime',
        'proposal_voided_at'       => 'datetime',
    ];

    protected $hidden = [
        'ip_address',
        'proposal_acceptance_token',
        'proposal_accepted_ip',
        'proposal_accepted_user_agent',
        'proposal_pdf_path',
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

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QuoteRequestItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function communications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerCommunication::class)->orderByDesc('created_at');
    }
}
