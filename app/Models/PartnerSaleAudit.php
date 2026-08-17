<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only trail of every change to a partner sale.
 *
 * `actor_label` is denormalised on purpose: `actor_id` points at a partner
 * user or admin who may later be deleted, and an audit row reading
 * "changed by #47" after that user is gone is not an audit trail.
 */
class PartnerSaleAudit extends Model
{
    use RecordsStaffActivity;

    /** Append-only — there is no updated_at and nothing ever updates a row. */
    public $timestamps = false;

    protected $fillable = [
        'partner_sale_id',
        'action',
        'actor_type',
        'actor_id',
        'actor_label',
        'changes',
        'ip_address',
    ];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PartnerSale::class, 'partner_sale_id');
    }

    /**
     * Record a change. `$changes` should carry only the fields that actually
     * moved, as ['field' => ['from' => x, 'to' => y]].
     */
    public static function record(
        int $saleId,
        string $action,
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $actorLabel = null,
        ?array $changes = null,
        ?string $ip = null,
    ): self {
        return static::create([
            'partner_sale_id' => $saleId,
            'action'          => $action,
            'actor_type'      => $actorType,
            'actor_id'        => $actorId,
            'actor_label'     => $actorLabel,
            'changes'         => $changes,
            'ip_address'      => $ip,
            'created_at'      => now(),
        ]);
    }

    /**
     * Staff-side rows only — a partner entering their own sale is their work,
     * not ours.
     */
    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromPartnerSaleAudit($this);
    }
}
