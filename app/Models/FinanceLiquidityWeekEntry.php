<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One breakdown line of the weekly liquidity board — the rolling four-week
 * version of FinanceLiquidityEntry's monthly buckets. `week_start` is always
 * the Monday of the week the entry belongs to; the visible window (current
 * week plus the three ahead) is computed from today's date, never stored, so
 * closed weeks fall out of view while their rows stay put as history.
 */
class FinanceLiquidityWeekEntry extends Model
{
    /** Same vocabulary as the monthly board, so nothing needs translating. */
    public const LINES = FinanceLiquidityEntry::LINES;

    /** Lines that represent money leaving; `revenue_payment` is money arriving. */
    public const OUTFLOW_LINES = [
        'cost_of_sales', 'rent', 'salaries', 'tax_obligations',
        'internet_phone', 'loan_payment', 'consultancy',
    ];

    protected $fillable = [
        'week_start',
        'line',
        'description',
        'reference',
        'amount',
        'recorded_by',
    ];

    protected $casts = [
        'week_start' => 'date',
        'amount'     => 'decimal:2',
    ];

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recorded_by');
    }
}
