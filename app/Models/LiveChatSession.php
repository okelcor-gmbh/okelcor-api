<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveChatSession extends Model
{
    protected $fillable = [
        'customer_id',
        'admin_id',
        'communication_id',
        'status',
        'closed_reason',
        'accepted_at',
        'closed_at',
        'last_message_at',
    ];

    protected $casts = [
        'accepted_at'      => 'datetime',
        'closed_at'        => 'datetime',
        'last_message_at'  => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(CustomerCommunication::class, 'communication_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LiveChatMessage::class, 'session_id')->orderBy('created_at');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
