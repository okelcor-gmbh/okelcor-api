<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkEmailCampaign extends Model
{
    protected $fillable = [
        'subject',
        'body_html',
        'filters',
        'total_recipients',
        'sent_count',
        'failed_count',
        'status',
        'created_by',
        'completed_at',
    ];

    protected $casts = [
        'filters'      => 'array',
        'completed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BulkEmailCampaignRecipient::class, 'campaign_id');
    }
}
