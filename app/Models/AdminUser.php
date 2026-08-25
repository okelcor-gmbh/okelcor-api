<?php

namespace App\Models;

use App\Support\AdminPermissions;
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
        // Deliberately NOT in $fillable: overrides change what a person can
        // do, so they are only ever written by the dedicated endpoint in
        // AdminUserController::updatePermissions, never by mass assignment.
        'permission_grants'        => 'array',
        'permission_revokes'       => 'array',
    ];

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * What this person can actually do: role baseline + per-user grants,
     * minus per-user revokes.
     *
     * super_admin is immune to overrides in both directions — the role is
     * the system's break-glass and a revoke that could lock the last
     * super admin out of admin management must not be storable, let alone
     * effective. Columns are survived-if-absent (pre-migration deploys and
     * the many tests that build a minimal admin_users table).
     *
     * @return array<int, string>
     */
    public function effectivePermissions(): array
    {
        $base = AdminPermissions::for($this->role);

        if ($this->role === 'super_admin') {
            return $base;
        }

        $known   = array_keys(AdminPermissions::MAP);
        $grants  = array_intersect((array) ($this->permission_grants ?? []), $known);
        $revokes = (array) ($this->permission_revokes ?? []);

        return array_values(array_diff(array_unique(array_merge($base, $grants)), $revokes));
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions(), true);
    }

    /** True when this user carries any override beyond their role. */
    public function hasPermissionOverrides(): bool
    {
        return ! empty($this->permission_grants) || ! empty($this->permission_revokes);
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
