<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminInsight extends Model
{
    protected $fillable = [
        'external_id',
        'category',
        'severity',
        'headline',
        'detail',
        'action_url',
        'rank',
        'generated_at',
    ];

    protected $casts = [
        'rank'         => 'integer',
        'generated_at' => 'datetime',
    ];
}
