<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * One after-sales claim — a damaged delivery, a wrong item, a shortage —
 * pulled out of the e-mail thread it used to live in and given a status, an
 * assignee and a place in My Work.
 */
class Claim extends Model
{
    use RecordsStaffActivity;

    private static ?bool $available = null;

    public const TYPES = [
        'damage', 'wrong_item', 'shortage', 'quality', 'warranty', 'delivery', 'other',
    ];

    public const TYPE_LABELS = [
        'damage'     => 'Transport damage',
        'wrong_item' => 'Wrong item delivered',
        'shortage'   => 'Shortage',
        'quality'    => 'Quality defect',
        'warranty'   => 'Warranty',
        'delivery'   => 'Delivery problem',
        'other'      => 'Other',
    ];

    public const STATUSES = [
        'new', 'in_review', 'awaiting_customer', 'approved', 'rejected', 'closed',
    ];

    public const STATUS_LABELS = [
        'new'               => 'New',
        'in_review'         => 'In review',
        'awaiting_customer' => 'Awaiting customer',
        'approved'          => 'Approved',
        'rejected'          => 'Rejected',
        'closed'            => 'Closed',
    ];

    /**
     * Statuses that mean the claim needs nobody's attention any more. Only
     * `closed`: an approved claim still owes the customer a credit note or
     * replacement, and a rejected one still owes them the reasons — both
     * stay on the assignee's plate until someone closes the loop.
     */
    public const CLOSED_STATUSES = ['closed'];

    /** The two statuses that count as a decision made. */
    public const RESOLVED_STATUSES = ['approved', 'rejected'];

    protected $fillable = [
        'ref',
        'order_id',
        'order_number',
        'customer_name',
        'customer_email',
        'customer_company',
        'type',
        'description',
        'quantity',
        'status',
        'outcome_note',
        'assigned_admin_id',
        'created_by',
        'resolved_at',
        'resolved_by',
        'closed_at',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('claims');
    }

    public static function forgetAvailableCheck(): void
    {
        self::$available = null;
    }

    protected static function booted(): void
    {
        // The ref needs the id, so it is stamped right after insert.
        // saveQuietly: the created→saved sequence already ran the ledger
        // hook once, and the ref alone is not a second piece of work.
        static::created(function (Claim $claim) {
            if (! $claim->ref) {
                $claim->forceFill(['ref' => sprintf('CLM-%05d', $claim->id)])->saveQuietly();
            }
        });
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromClaim($this);
    }
}
