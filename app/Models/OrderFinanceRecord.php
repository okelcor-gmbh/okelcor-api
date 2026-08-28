<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * The revenue side of an order's profitability, one row per order: the
 * finalized invoice the customer agreed to, its uploaded PDF, and finance's
 * verification of the profit calculation built on it.
 *
 * Profit is never stored here — it is computed by OrderProfitabilityService
 * from this record and the order's cost lines, in one place, so the order
 * page, the list, the export and the dashboard cannot disagree.
 */
class OrderFinanceRecord extends Model
{
    /** Memoised so the hot paths ask the schema once, not per order. */
    private static ?bool $available = null;

    protected $fillable = [
        'order_id',
        'order_ref',
        'revenue_invoice_number',
        'revenue_amount',
        'revenue_currency',
        'revenue_issued_on',
        'revenue_finalized_at',
        'customer_agreed_at',
        'revenue_file_path',
        'revenue_original_filename',
        'revenue_mime_type',
        'revenue_file_size',
        'revenue_uploaded_at',
        'revenue_set_by',
        'verified_at',
        'verified_by',
        'verified_note',
    ];

    protected $hidden = [
        // A storage path is server internals. The API exposes has_file and a
        // download route instead — same rule as finance_invoices.
        'revenue_file_path',
    ];

    protected $casts = [
        'revenue_amount'       => 'decimal:2',
        'revenue_issued_on'    => 'date',
        'revenue_finalized_at' => 'datetime',
        'customer_agreed_at'   => 'datetime',
        'revenue_uploaded_at'  => 'datetime',
        'verified_at'          => 'datetime',
    ];

    /**
     * Whether the profitability tables exist yet. The order detail endpoint
     * embeds a finance block, and that endpoint must keep working between the
     * code deploying and the migration running — a reporting table must never
     * be able to fail the thing it reports on.
     */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('order_finance_records')
            && Schema::hasTable('order_cost_lines');
    }

    /** For the test harness, which creates the tables after the app boots. */
    public static function forgetAvailableCheck(): void
    {
        self::$available = null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'revenue_set_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verified_by');
    }

    public function hasRevenueInvoice(): bool
    {
        return $this->revenue_amount !== null || $this->revenue_invoice_number !== null;
    }

    public function hasFile(): bool
    {
        return $this->getRawOriginal('revenue_file_path') !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
