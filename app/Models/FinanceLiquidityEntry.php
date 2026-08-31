<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One breakdown line inside the "Finance Liquidity Working" table — a rent
 * payment, a payroll run, an expected customer receipt — bucketed into the
 * open current month or the next month forecast.
 */
class FinanceLiquidityEntry extends Model
{
    /**
     * The fixed liquidity lines, in the row order of finance's "Liquidity
     * File V1" summary grid. Keys are stable (his JSON backups and the
     * import command both map onto them); labels follow the file —
     * "Tax Obligations Salary" became plain "Tax Obligations" there, and
     * `it_expenses` is new (the file's "Other Expenses" section).
     */
    public const LINES = [
        'bank_balance'    => 'Bank Balance',
        'cost_of_sales'   => 'Cost of sales',
        'rent'            => 'Rent',
        'salaries'        => 'Salaries',
        'tax_obligations' => 'Tax Obligations',
        'loan_payment'    => 'Loan Payment',
        'consultancy'     => 'Consultancy',
        'it_expenses'     => 'IT Expenses',
        'internet_phone'  => 'Internet & Phone',
        'revenue_payment' => 'Revenue Payment',
    ];

    /**
     * The lines that feed the computed rows, matching the file's formulas:
     * Cash Position = Bank Balance + every expense line;
     * Forecasted Cash Position = Cash Position + Revenue Payment.
     * One constant so the grid's arithmetic cannot drift from the row list.
     */
    public const EXPENSE_LINES = [
        'cost_of_sales', 'rent', 'salaries', 'tax_obligations',
        'loan_payment', 'consultancy', 'it_expenses', 'internet_phone',
    ];

    /** Legacy two-period buckets — kept so the D13 restore path still works. */
    public const PERIODS = ['open_current', 'next_month'];

    /** Today's ISO week, in the grid's key format — e.g. '2026-W36'. */
    public static function currentWeekKey(): string
    {
        return now()->format('o-\WW');
    }

    /**
     * A week that has ended is CLOSED (Session 106): its figures are what
     * happened, not a plan. Zero-padded 'YYYY-Wnn' keys compare correctly
     * as strings, across year ends included ('2026-W53' < '2027-W01').
     */
    public static function isClosedWeek(string $weekKey): bool
    {
        return $weekKey < self::currentWeekKey();
    }

    protected $fillable = [
        'line',
        'period',
        'week_key',
        'supplier',
        'description',
        'reference',
        'amount',
        'currency',
        'comment',
    ];

    protected $casts = [
        'amount' => 'float',
    ];
}
