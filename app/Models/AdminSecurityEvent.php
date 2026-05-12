<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSecurityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type', 'severity', 'admin_id', 'admin_email', 'admin_role',
        'ip_address', 'user_agent', 'description', 'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
