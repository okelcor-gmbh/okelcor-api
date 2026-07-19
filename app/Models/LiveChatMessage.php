<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveChatMessage extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'session_id',
        'sender_type',
        'sender_id',
        'body',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveChatSession::class, 'session_id');
    }
}
