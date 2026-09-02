<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * One tracked record on the finance snapshot board — a proposal awaiting a
 * client, an unpaid invoice, a shipment on the water — attributed to the
 * staff member handling it. See the migration for the design note.
 */
class FinanceSnapshotItem extends Model
{
    use RecordsStaffActivity;

    /** The six pipeline boxes, exactly as finance named them. */
    public const CATEGORIES = [
        'OPEN PROPOSALS',
        'OPEN ORDERS',
        'OUTSTANDING INVOICES',
        'SHIPMENTS IN TRANSIT',
        'DELIVERY CONFIRMATIONS',
        'PENDING RECEIPTS',
    ];

    public const STATUSES = [
        'Pending',
        'Sent',
        'In Progress',
        'Under Review',
        'Approved',
        'Completed',
        'Cancelled',
    ];

    /** Statuses that mean the record needs nobody's attention any more. */
    public const CLOSED_STATUSES = ['Completed', 'Cancelled'];

    protected $fillable = [
        'category',
        'person',
        'assigned_admin_id',
        'ref',
        'date',
        'client',
        'status',
        'comment',
        'amount',
        'created_by',
    ];

    protected $casts = [
        'date'   => 'date:Y-m-d',
        'amount' => 'float',
    ];

    public function assignee()
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromFinanceSnapshotItem($this);
    }
}
