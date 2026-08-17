<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EbayListingLog extends Model
{
    use RecordsStaffActivity;

    // Append-only audit table — no updated_at column
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'admin_user_id',
        'sku',
        'action',
        'ebay_item_id',
        'ebay_offer_id',
        'status',
        'error_message',
        'response_code',
        'payload_summary',
    ];

    protected $casts = [
        'payload_summary' => 'array',
        'created_at'      => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromEbayListingLog($this);
    }
}
