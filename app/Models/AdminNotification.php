<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminNotification extends Model
{
    protected $fillable = [
        'admin_user_id',
        'type',
        'severity',
        'title',
        'body',
        'message',      // legacy mirror of body (kept for pre-CRM-3B consumers)
        'action_url',
        'link',         // legacy mirror of action_url
        'related_type',
        'related_id',
        'read_at',
        'dismissed_at',
        'metadata',
    ];

    protected $casts = [
        'read_at'      => 'datetime',
        'dismissed_at' => 'datetime',
        'metadata'     => 'array',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at')->whereNull('dismissed_at');
    }

    public function scopeForUser($query, int $adminUserId)
    {
        return $query->where('admin_user_id', $adminUserId);
    }
}
