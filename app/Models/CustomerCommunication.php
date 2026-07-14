<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCommunication extends Model
{
    protected $fillable = [
        'customer_id',
        'quote_request_id',
        'order_id',
        'admin_user_id',
        'type',
        'direction',
        'channel',
        'subject',
        'body',
        'cc',
        'attachments',
        'message_id',
        'in_reply_to',
        'status',
        'scheduled_at',
        'completed_at',
        'staff_read_at',
        'customer_read_at',
        'metadata',
    ];

    protected $casts = [
        'scheduled_at'     => 'datetime',
        'completed_at'     => 'datetime',
        'staff_read_at'    => 'datetime',
        'customer_read_at' => 'datetime',
        'cc'               => 'array',
        'attachments'      => 'array',
        'metadata'         => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
