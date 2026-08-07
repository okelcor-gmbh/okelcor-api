<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One reported sale line: "we sold tyre 315-70 rim 22.5, X pieces, at this
 * amount". That sentence from the brief is the whole data model.
 *
 * Owned by the organisation; `entered_by_user_id` records who typed it.
 */
class PartnerSale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'partner_org_id',
        'entered_by_user_id',
        'client_generated_id',
        'client_revision',
        'sold_at',
        'size',
        'brand',
        'tyre_type',
        'product_id',
        'quantity',
        'unit_price',
        'total_amount',
        'currency',
        'customer_name',
        'notes',
        'source',
        'status',
        'verified_by',
        'verified_at',
        'review_note',
    ];

    protected $casts = [
        'sold_at'         => 'date',
        'quantity'        => 'integer',
        'client_revision' => 'integer',
        'unit_price'      => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'verified_at'     => 'datetime',
    ];

    /** Values accepted by the API. Strings, not a DB ENUM — see the migration. */
    public const TYRE_TYPES = ['pcr', 'tbr', 'otr', 'used'];
    public const STATUSES   = ['submitted', 'verified', 'disputed'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganisation::class, 'partner_org_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(PartnerUser::class, 'entered_by_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verified_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(PartnerSaleAudit::class);
    }

    /**
     * Whether a partner may still edit this entry.
     *
     * Measured from the server's `created_at`, never from `sold_at` or any
     * device-supplied timestamp: `sold_at` is partner-declared and backdatable,
     * so keying the window off it would let anyone reopen a locked entry by
     * editing the date.
     */
    public function isWithinEditWindow(): bool
    {
        if ($this->created_at === null) {
            return true; // not yet persisted
        }

        return $this->created_at
            ->copy()
            ->addHours((int) config('partner.edit_window_hours', 24))
            ->isFuture();
    }

    /**
     * Total is always derived server-side. The client sends quantity and unit
     * price; it never sends a total, so a stored total cannot disagree with
     * the line it came from.
     */
    public static function computeTotal(int $quantity, float|string $unitPrice): string
    {
        return number_format($quantity * (float) $unitPrice, 2, '.', '');
    }
}
