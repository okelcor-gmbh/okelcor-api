<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingContact extends Model
{
    protected $fillable = [
        'email',
        'first_name',
        'last_name',
        'phone',
        'company',
        'country',
        'vat_id',
        'labels',
        'source',
        'status',
        'unsubscribe_token',
        'imported_at',
    ];

    protected $hidden = [
        'unsubscribe_token',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
    ];
}
