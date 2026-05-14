<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EbayToken extends Model
{
    protected $fillable = [
        'marketplace_id',
        'seller_username',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'scopes',
        'connected_at',
        'last_refreshed_at',
        'is_active',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token'             => 'encrypted',
        'refresh_token'            => 'encrypted',
        'access_token_expires_at'  => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'connected_at'             => 'datetime',
        'last_refreshed_at'        => 'datetime',
        'is_active'                => 'boolean',
        'scopes'                   => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
