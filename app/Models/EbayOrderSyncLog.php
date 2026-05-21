<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EbayOrderSyncLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'ebay_order_id',
        'order_id',
        'action',
        'status',
        'error_message',
        'payload_summary',
    ];

    protected $casts = [
        'payload_summary' => 'array',
        'created_at'      => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
