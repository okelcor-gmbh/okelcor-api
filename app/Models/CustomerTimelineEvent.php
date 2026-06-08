<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTimelineEvent extends Model
{
    /** Append-only — only created_at is maintained. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'admin_user_id',
        'event_type',
        'title',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
