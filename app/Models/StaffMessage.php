<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One internal message from a staff member to one or more colleagues.
 *
 * Distinct from CustomerCommunication on purpose — see the migration for
 * why this is not a nullable customer_id on that table.
 */
class StaffMessage extends Model
{
    protected $fillable = [
        'thread_id',
        'sender_admin_id',
        'sender_label',
        'subject',
        'body',
        'attachments',
        'in_reply_to_id',
        'forwarded_from_communication_id',
        'forwarded_from_customer_id',
        'forwarded_from_quote_request_id',
    ];

    protected $casts = [
        'attachments' => 'array',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'sender_admin_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(StaffMessageRecipient::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_id');
    }

    public function isForward(): bool
    {
        return $this->forwarded_from_communication_id !== null;
    }

    /**
     * Who to show as the sender. Falls back to the denormalised label so a
     * message still reads correctly after the sending admin's account is
     * deleted — the same reasoning as PartnerSaleAudit::$actor_label.
     */
    public function senderName(): string
    {
        if ($this->sender) {
            return trim($this->sender->display_name ?: $this->sender->name) ?: $this->sender->email;
        }

        return $this->sender_label ?: 'A former colleague';
    }
}
