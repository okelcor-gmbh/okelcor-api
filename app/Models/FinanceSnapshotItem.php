<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One tracked record on the finance snapshot board — a proposal awaiting a
 * client, an unpaid invoice, a shipment on the water — attributed to the
 * staff member handling it. See the migration for the design note.
 */
class FinanceSnapshotItem extends Model
{
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

    protected $fillable = [
        'category',
        'person',
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
}
