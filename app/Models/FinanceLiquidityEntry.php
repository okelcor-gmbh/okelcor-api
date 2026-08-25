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
     * The fixed liquidity lines, keyed exactly as finance's original board
     * keyed them so his JSON backups restore without translation.
     */
    public const LINES = [
        'bank_balance'    => 'Bank Balance',
        'cost_of_sales'   => 'Cost of sales',
        'rent'            => 'Rent',
        'salaries'        => 'Salaries',
        'tax_obligations' => 'Tax Obligations Salary',
        'internet_phone'  => 'Internet & Phone',
        'loan_payment'    => 'Loan Payment',
        'consultancy'     => 'Consultancy',
        'revenue_payment' => 'Revenue Payment',
    ];

    public const PERIODS = ['open_current', 'next_month'];

    protected $fillable = [
        'line',
        'period',
        'description',
        'reference',
        'amount',
    ];

    protected $casts = [
        'amount' => 'float',
    ];
}
