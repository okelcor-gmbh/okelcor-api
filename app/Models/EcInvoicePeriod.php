<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The filing state of one ZM reporting period ('2026-Q1' or '2026-05').
 * Created lazily the first time a period is touched; the badge on the EC
 * Invoice List reads it, and marking a period submitted stamps when.
 */
class EcInvoicePeriod extends Model
{
    use RecordsStaffActivity;

    public const STATUSES = ['draft', 'ready', 'submitted'];

    protected $fillable = [
        'period',
        'status',
        'submitted_at',
        'updated_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    /** '2026-Q1' (quarter) or '2026-05' (month) — the two § 18a shapes. */
    public static function isValidPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(Q[1-4]|0[1-9]|1[0-2])$/', $period);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromEcInvoicePeriod($this);
    }
}
