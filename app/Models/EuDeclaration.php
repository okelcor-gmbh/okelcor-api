<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EuDeclaration extends Model
{
    protected $fillable = [
        'order_id',
        'customer_id',
        'invoice_id',
        'order_ref',
        'company_name',
        'customer_email',
        'customer_address',
        'street',
        'city',
        'postal_code',
        'vat_number',
        'country',
        'goods_description',
        'quantity_description',
        'member_state_of_entry',
        'place_of_entry',
        'month_year_received',
        'self_transported',
        'month_year_transport_ended',
        'representative_name',
        'representative_title',
        'signed_name',
        'accepted_terms',
        'issue_date',
        'signed_at',
        'signature_path',
        'pdf_path',
        'status',
        'admin_acknowledged_at',
        'admin_acknowledged_by',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'self_transported'      => 'boolean',
        'accepted_terms'        => 'boolean',
        'issue_date'            => 'date',
        'signed_at'             => 'datetime',
        'admin_acknowledged_at' => 'datetime',
    ];

    protected $hidden = [
        'ip_address',
        'user_agent',
        'signature_path',  // served through a controller, never exposed raw
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
