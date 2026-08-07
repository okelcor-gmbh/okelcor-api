<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person at a partner organisation who logs sales.
 *
 * Authenticates with phone + PIN and holds a Sanctum token, exactly like
 * Customer and AdminUser — PartnerAuth isolates the three by token type.
 */
class PartnerUser extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'partner_org_id',
        'name',
        'phone',
        'pin_hash',
        'role',
        'is_active',
        'must_change_pin',
        'pin_changed_at',
        'failed_pin_attempts',
        'locked_until',
        'last_login_at',
    ];

    /**
     * `pin_hash` must never reach a response body. It is a bcrypt hash of a
     * 6-digit secret — a short, entirely numeric plaintext — so an offline
     * attack on a leaked hash is far more tractable than on a password.
     */
    protected $hidden = [
        'pin_hash',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'must_change_pin'     => 'boolean',
        'failed_pin_attempts' => 'integer',
        'pin_changed_at'      => 'datetime',
        'locked_until'        => 'datetime',
        'last_login_at'       => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganisation::class, 'partner_org_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PartnerSale::class, 'entered_by_user_id');
    }

    /**
     * Reduce a phone number to digits, dropping a leading + and any spaces,
     * dashes or brackets.
     *
     * Without this "+233 24 123 4567", "233241234567" and "(0)241234567" are
     * three different logins for one person, and `phone` is UNIQUE — the
     * second attempt to create the same partner would fail with a confusing
     * duplicate error instead of matching.
     *
     * Deliberately NOT doing country-code inference: guessing that a local
     * 0-prefixed number belongs to the organisation's country would silently
     * create the wrong account for a partner using a foreign SIM, which is
     * common in these markets. Admin enters the full international number.
     */
    public static function normalisePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function canLogIn(): bool
    {
        return $this->is_active
            && ! $this->isLocked()
            && $this->organisation?->isActive() === true;
    }
}
