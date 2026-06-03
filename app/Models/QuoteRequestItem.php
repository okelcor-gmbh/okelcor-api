<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteRequestItem extends Model
{
    protected $fillable = [
        'quote_request_id',
        'product_id',
        'brand',
        'model',
        'size',
        'season',
        'load_index',
        'speed_index',
        'condition',
        'quantity',
        'unit_price',
        'currency',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Computed line total — null when unit_price is not set. */
    public function getLineTotalAttribute(): ?float
    {
        if ($this->unit_price === null) {
            return null;
        }

        return round((float) $this->unit_price * $this->quantity, 2);
    }
}
