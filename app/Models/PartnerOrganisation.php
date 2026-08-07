<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A partner organisation — a distributor selling Okelcor product in another
 * market. Sales belong here, not to the individual who typed them in.
 */
class PartnerOrganisation extends Model
{
    protected $fillable = [
        'name',
        'country',
        'country_code',
        'default_currency',
        'contact_email',
        'contact_phone',
        'status',
        'notes',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(PartnerUser::class, 'partner_org_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PartnerSale::class, 'partner_org_id');
    }

    /**
     * Market is derived from country rather than stored.
     *
     * `customers.market_region` and `marketing_contacts.market` are already
     * two separate market vocabularies here; storing a third would be a third
     * thing to keep in sync and to correct when it drifts. Markets are
     * discovered from the distinct set of partner countries — the same
     * auto-discovery approach Session 72 used for marketing contacts, so
     * adding a market means adding a partner, not editing a list.
     */
    public function getMarketAttribute(): string
    {
        return Str::lower(trim($this->country ?? ''));
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    /** Every market currently in use, for admin filter dropdowns. */
    public static function markets(): array
    {
        return static::query()
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->map(fn ($c) => Str::lower(trim($c)))
            ->unique()
            ->values()
            ->all();
    }
}
