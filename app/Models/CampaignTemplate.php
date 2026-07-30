<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable campaign design saved by the marketing team — the blocks and theme,
 * with no subject or audience, so it can be reused for any send.
 *
 * The built-in starting points are NOT rows here; see
 * App\Support\CampaignStarterTemplates.
 */
class CampaignTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'blocks',
        'theme',
        'created_by',
    ];

    protected $casts = [
        'blocks' => 'array',
        'theme'  => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
