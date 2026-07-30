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
        // The block-based source of `body_html` when the campaign was designed
        // in the editor rather than pasted in as HTML. Kept so a sent campaign
        // can be reopened or duplicated; the send path only ever reads
        // `body_html`.
        'blocks',
        'theme',
        'body_text',
        'filters',
        'total_recipients',
        'sent_count',
        'failed_count',
        'status',
        'created_by',
        'completed_at',
    ];

    protected $casts = [
        'blocks'       => 'array',
        'theme'        => 'array',
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
