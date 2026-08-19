<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    /**
     * Every product gets a slug at creation, wherever it is created from —
     * the admin form is only one of the doors (the Wix import, the eBay sync
     * and the seeder all call Product::create directly). A hook on the model
     * is the one place that covers all of them; an endpoint-level generator
     * would leave every other path minting products with no URL.
     *
     * Creation only. Renames never touch an existing slug — that URL is in
     * Google's index and in sent campaigns; moving it is a deliberate act
     * through the admin slug field, not a side effect.
     */
    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug) && static::slugColumnExists()) {
                $product->slug = app(\App\Services\ProductSlugger::class)->generate(
                    (string) $product->brand,
                    (string) $product->name,
                    (string) $product->season,
                );
            }
        });
    }

    /**
     * Deploy-order safety: with this code live before migration #42 runs, a
     * product created by the imports or the webhook path must not try to
     * INSERT a column that is not there yet. Cached per request — the Wix
     * import creates thousands of rows and must not pay a SHOW COLUMNS each.
     */
    private static ?bool $hasSlugColumn = null;

    private static function slugColumnExists(): bool
    {
        return static::$hasSlugColumn ??= \Illuminate\Support\Facades\Schema::hasColumn('products', 'slug');
    }

    /** @internal test harnesses rebuild the schema between tests. */
    public static function flushSlugColumnCache(): void
    {
        static::$hasSlugColumn = null;
    }
    protected $fillable = [
        'sku',
        'slug',
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
        'description_html',
        'specs',
        'shipping_info',
        'returns_info',
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
        'specs'               => 'array',
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
