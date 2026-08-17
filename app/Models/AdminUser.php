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
        'job_title',
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

    /**
     * What this person does, as a human would say it.
     *
     * The role is a permission set and has never been a job description — two
     * order managers and the person running operations all hold `admin`,
     * because all three need customers, campaigns and quote requests. Falling
     * back to a tidied role is only so nothing renders blank before somebody
     * sets a title; it is not the answer, and any screen that groups people by
     * work should read this rather than `role`.
     */
    public function jobTitle(): string
    {
        $title = $this->attributes['job_title'] ?? null;

        if (is_string($title) && trim($title) !== '') {
            return trim($title);
        }

        return ucwords(str_replace('_', ' ', (string) $this->role));
    }

    /** True when somebody has actually said what this person does. */
    public function hasJobTitle(): bool
    {
        $title = $this->attributes['job_title'] ?? null;

        return is_string($title) && trim($title) !== '';
    }

    public function media()
    {
        return $this->hasMany(Media::class, 'uploaded_by');
    }
}
