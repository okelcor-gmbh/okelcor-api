<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signature on an order confirmation.
 *
 * Two are required before the confirmation goes to the customer — one from
 * operations, one from finance — and they must be two different people. The
 * role and name are copied onto the row rather than read back through the
 * relation: an approval is a statement about who someone was at the moment they
 * made it, and reading it live would rewrite history the day that person
 * changes role or leaves.
 */
class OrderSignoff extends Model
{
    use RecordsStaffActivity;

    public const SLOT_OPS     = 'ops';
    public const SLOT_FINANCE = 'finance';

    /** Both slots must be filled, in no particular order. */
    public const SLOTS = [self::SLOT_OPS, self::SLOT_FINANCE];

    /** The permission that entitles someone to sign each slot. */
    public const SLOT_PERMISSIONS = [
        self::SLOT_OPS     => 'orders.signoff_ops',
        self::SLOT_FINANCE => 'orders.signoff_finance',
    ];

    public const SLOT_LABELS = [
        self::SLOT_OPS     => 'Operations',
        self::SLOT_FINANCE => 'Finance',
    ];

    protected $fillable = [
        'order_id',
        'order_ref',
        'slot',
        'admin_user_id',
        'admin_role',
        'admin_name',
        'signed_at',
        'note',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
        'active',
    ];

    protected $casts = [
        'signed_at'  => 'datetime',
        'revoked_at' => 'datetime',
        'active'     => 'integer',
    ];

    /**
     * Signatures that still stand. `active` is 1 or NULL and never 0 — that is
     * what lets the unique index allow many withdrawn rows and one live one.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotNull('active');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'revoked_by');
    }

    /**
     * A signature is the clearest single piece of evidence of work in the whole
     * system: one named person, one moment, one order.
     */
    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromSignoff($this);
    }
}
