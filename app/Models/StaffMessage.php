<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffMessage extends Model
{
    protected $fillable = [
        'sender_admin_user_id',
        'subject',
        'body',
        'attachments',
        'in_reply_to_id',
        'thread_root_id',
        'metadata',
    ];

    protected $casts = [
        'attachments' => 'array',
        'metadata'    => 'array',
    ];

    public function sender()
    {
        return $this->belongsTo(AdminUser::class, 'sender_admin_user_id');
    }

    public function recipientLinks()
    {
        return $this->hasMany(StaffMessageRecipient::class, 'staff_message_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'in_reply_to_id');
    }

    /** The id every message in this thread shares (a root carries its own). */
    public function threadRootId(): int
    {
        return $this->thread_root_id ?? $this->id;
    }

    /** Sender and recipients may see a message; nobody else can. */
    public function visibleTo(AdminUser $user): bool
    {
        return $this->sender_admin_user_id === $user->id
            || $this->recipientLinks()->where('recipient_admin_user_id', $user->id)->exists();
    }
}
