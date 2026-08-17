<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSecurityEvent extends Model
{
    use RecordsStaffActivity;

    public $timestamps = false;

    protected $fillable = [
        'type', 'severity', 'admin_id', 'admin_email', 'admin_role',
        'ip_address', 'user_agent', 'description', 'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    /**
     * Only the whitelisted administrative types reach the ledger. Most of this
     * table is logins and denials — presence data, which the ledger refuses to
     * measure.
     */
    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromSecurityEvent($this);
    }
}
