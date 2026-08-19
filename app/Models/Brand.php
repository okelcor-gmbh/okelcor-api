<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'sort_order',
        'is_active',

        // Session 93 — brand-level content defaults. Entered once per brand;
        // every product without its own value inherits at read time
        // (product → brand → site setting). Only json-backed sheet attributes
        // belong in `specs` — a brand does not have one width or one EAN.
        'description_html',
        'specs',
        'shipping_info',
        'returns_info',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'specs'     => 'array',
    ];
}
