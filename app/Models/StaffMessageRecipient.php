<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on the receiving end of a StaffMessage, with their own read
 * state and their own e-mail delivery outcome.
 *
 * Per-recipient delivery status matters: with three people on a message and
 * one bad address, a single status column on the message would have to
 * choose between reporting "sent" (hiding a failure) or "failed" (implying
 * nobody got it). Neither is true.
 */
class StaffMessageRecipient extends Model
{
    public const KINDS = ['to', 'cc'];

    protected $fillable = [
        'staff_message_id',
        'admin_user_id',
        'kind',
        'read_at',
        'email_status',
        'email_error',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(StaffMessage::class, 'staff_message_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
