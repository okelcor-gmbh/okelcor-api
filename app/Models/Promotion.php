<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = [
        'title',
        'code',
        'subheadline',
        'short_text',
        'emoji',
        'placement',
        'brand_name',
        'customer_type_target',
        'discount_pct',
        'button_text',
        'button_link',
        'image_url',
        'is_active',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'discount_pct' => 'decimal:2',
        'start_date'   => 'date:Y-m-d',
        'end_date'     => 'date:Y-m-d',
    ];
}
