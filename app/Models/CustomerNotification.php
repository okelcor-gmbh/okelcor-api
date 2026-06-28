<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer Portal Notification — the in-app twin of a transactional email.
 *
 * Scoped to a single customer. Excluded from feeds once dismissed; counted as
 * unread while read_at IS NULL AND dismissed_at IS NULL.
 */
class CustomerNotification extends Model
{
    protected $fillable = [
        'customer_id',
        'type',
        'title',
        'body',
        'severity',
        'action_url',
        'related_type',
        'related_id',
        'read_at',
        'dismissed_at',
        'email_sent_at',
        'metadata',
    ];

    protected $casts = [
        'read_at'       => 'datetime',
        'dismissed_at'  => 'datetime',
        'email_sent_at' => 'datetime',
        'metadata'      => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at')->whereNull('dismissed_at');
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeVisible($query)
    {
        return $query->whereNull('dismissed_at');
    }
}
