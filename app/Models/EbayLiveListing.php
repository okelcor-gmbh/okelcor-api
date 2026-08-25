<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One SKU as eBay actually shows it — see the migration for the design
 * note. `product_id` is the match into our catalogue, null when eBay
 * holds a listing our panel does not know about.
 */
class EbayLiveListing extends Model
{
    protected $fillable = [
        'sku',
        'title',
        'offer_id',
        'listing_id',
        'status',
        'price',
        'currency',
        'quantity',
        'product_id',
        'fetched_at',
    ];

    protected $casts = [
        'price'      => 'float',
        'fetched_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
