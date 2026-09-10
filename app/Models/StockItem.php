<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One physical stock line in the finance ledger: a brand + size +
 * condition grade, with the pieces on the shelf and the latest supplier
 * cost. See the migration for why this is not `products`.
 */
class StockItem extends Model
{
    public const GRADES = ['Grade A', 'Grade B', 'Grade C'];

    protected $fillable = ['brand', 'size', 'tread', 'grade', 'qty', 'cost'];

    protected $casts = [
        'qty'  => 'integer',
        'cost' => 'decimal:2',
    ];
}
