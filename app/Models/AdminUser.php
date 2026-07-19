<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class AdminUser extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'display_name',
        'email',
        'password',
        'role',
        'last_login_at',
        'last_login_ip',
        'must_change_password',
        'is_active',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'email_signature',
        'available_for_chat',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'last_login_at'            => 'datetime',
        'two_factor_confirmed_at'  => 'datetime',
        'password'                 => 'hashed',
        'must_change_password'     => 'boolean',
        'is_active'                => 'boolean',
        'available_for_chat'       => 'boolean',
    ];

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function media()
    {
        return $this->hasMany(Media::class, 'uploaded_by');
    }
}
