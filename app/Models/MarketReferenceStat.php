<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One imported external figure about one country.
 *
 * Everything else in the market report is observed — what Okelcor's own
 * visitors, inquirers and customers did. These rows are the opposite: bought
 * or downloaded evidence about markets that may have produced no traffic at
 * all. The report keeps the two visually separate for that reason; mixing an
 * observed conversion rate with a national import statistic in one column
 * would make the second look like something Okelcor measured.
 */
class MarketReferenceStat extends Model
{
    protected $fillable = [
        'country_code',
        'metric',
        'value',
        'unit',
        'period',
        'source',
        'notes',
    ];

    protected $casts = [
        'value' => 'decimal:4',
    ];
}
