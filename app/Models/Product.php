<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'sku',
        'ean',
        'brand',
        'name',
        'size',
        'spec',
        'season',
        'type',
        'price',
        'price_b2b',
        'price_b2c',
        'description',
        'primary_image',
        'is_active',
        'sort_order',
        'width',
        'height',
        'rim',
        'load_index',
        'speed_rating',
        'stock',
        'cost_price',
        'ebay_listed',
        'ebay_item_id',
        'ebay_offer_id',
        'ebay_status',
        'ebay_last_synced_at',
        'ebay_sync_error',
        'in_stock',
        'condition_grade',
        'tread_depth_mm',
        'dot_code',
        'inspection_date',
        'inspection_photos',
    ];

    protected $casts = [
        'price'               => 'decimal:2',
        'price_b2b'           => 'decimal:2',
        'price_b2c'           => 'decimal:2',
        'cost_price'          => 'decimal:2',
        'is_active'           => 'boolean',
        'stock'               => 'integer',
        'ebay_listed'         => 'boolean',
        'ebay_last_synced_at' => 'datetime',
        'in_stock'            => 'boolean',
        'tread_depth_mm'      => 'decimal:1',
        'inspection_date'     => 'date',
        'inspection_photos'   => 'array',
    ];

    /**
     * True once an admin has entered any tyre-passport data. Used to omit the
     * whole `tyre_batch` block from API payloads rather than emit a card full
     * of nulls the frontend would have to render empty.
     */
    public function hasTyreBatchData(): bool
    {
        return $this->condition_grade !== null
            || $this->tread_depth_mm !== null
            || $this->dot_code !== null
            || $this->inspection_date !== null
            || ! empty($this->inspection_photos);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }
}
