<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class MarketingContact extends Model
{
    protected $fillable = [
        'email',
        'first_name',
        'last_name',
        'phone',
        'company',
        'country',
        'market',
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

    /**
     * Memoized per request — the membership table is consulted on every
     * formatted contact, and a deploy that lands code before migrations
     * shouldn't turn the whole contact list into a 500. When the table is
     * absent every method here degrades to the pre-multi-market behaviour of
     * the single `market` column.
     */
    private static ?bool $membershipTableExists = null;

    /** Re-entrancy guard for the membership-sync save hook. */
    private static bool $syncingMembership = false;

    /**
     * Guarantees the primary `market` column always has a matching membership
     * row, so a contact written the old single-column way — `create(['market'
     * => 'asia'])` anywhere in the codebase, now or in future — is still found
     * by the market-scoped contact list and campaign filters, which query
     * memberships. Without this, any writer that forgot to register membership
     * would produce a contact that is in a market according to its own row but
     * invisible to every list of that market.
     *
     * Only fires when `market` actually changed (or the row is new), so a bulk
     * import doesn't pay for a redundant write per contact.
     */
    protected static function booted(): void
    {
        static::saved(function (MarketingContact $contact) {
            if (self::$syncingMembership || ! self::supportsMultipleMarkets()) {
                return;
            }

            if (! $contact->market) {
                return;
            }

            if (! $contact->wasRecentlyCreated && ! $contact->wasChanged('market')) {
                return;
            }

            self::$syncingMembership = true;

            try {
                MarketingContactMarket::insertOrIgnore([[
                    'contact_id' => $contact->id,
                    'market'     => $contact->market,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]]);

                $contact->unsetRelation('marketMemberships');
            } finally {
                self::$syncingMembership = false;
            }
        });
    }

    public static function supportsMultipleMarkets(): bool
    {
        return self::$membershipTableExists ??= Schema::hasTable('marketing_contact_markets');
    }

    /**
     * Clears the memo. Only needed by tests, which create and drop the table
     * inside a single process — production resolves it once per request.
     */
    public static function forgetMultipleMarketsSupport(): void
    {
        self::$membershipTableExists = null;
    }

    public function marketMemberships(): HasMany
    {
        return $this->hasMany(MarketingContactMarket::class, 'contact_id');
    }

    /**
     * Every market this contact belongs to, oldest membership first — so the
     * market it was originally imported/added under stays first in the list.
     */
    public function marketNames(): array
    {
        if (! self::supportsMultipleMarkets()) {
            return $this->market ? [$this->market] : [];
        }

        $memberships = $this->relationLoaded('marketMemberships')
            ? $this->marketMemberships
            : $this->marketMemberships()->get();

        return $memberships->sortBy('id')->pluck('market')->unique()->values()->all();
    }

    /**
     * Adds memberships without touching existing ones. Returns the markets
     * actually added (already-held ones are silently skipped, so calling this
     * twice is harmless).
     *
     * @param  array<int, string>  $markets  already-normalized market keys
     * @return array<int, string>
     */
    public function addMarkets(array $markets): array
    {
        $markets = array_values(array_unique(array_filter($markets)));

        if (empty($markets) || ! self::supportsMultipleMarkets()) {
            // Single-column fallback: the last market given wins, which is the
            // best a single column can do.
            if (! empty($markets)) {
                $this->update(['market' => end($markets)]);
            }

            return $markets;
        }

        $held  = $this->marketNames();
        $added = array_values(array_diff($markets, $held));

        if (! empty($added)) {
            $now = now();
            MarketingContactMarket::insertOrIgnore(array_map(fn ($m) => [
                'contact_id' => $this->id,
                'market'     => $m,
                'created_at' => $now,
                'updated_at' => $now,
            ], $added));

            $this->unsetRelation('marketMemberships');
        }

        $this->refreshPrimaryMarket();

        return $added;
    }

    /**
     * Removes memberships. A contact must always keep at least one market —
     * stripping the last one would leave it invisible to every market-scoped
     * list and campaign filter with no way to find it again — so the removal
     * that would empty the contact is refused and reported instead.
     *
     * @param  array<int, string>  $markets  already-normalized market keys
     * @return array{removed: array<int, string>, refused_last: bool}
     */
    public function removeMarkets(array $markets): array
    {
        $markets = array_values(array_unique(array_filter($markets)));

        if (empty($markets) || ! self::supportsMultipleMarkets()) {
            return ['removed' => [], 'refused_last' => false];
        }

        $held      = $this->marketNames();
        $toRemove  = array_values(array_intersect($held, $markets));
        $remaining = array_values(array_diff($held, $toRemove));

        if (empty($toRemove)) {
            return ['removed' => [], 'refused_last' => false];
        }

        if (empty($remaining)) {
            return ['removed' => [], 'refused_last' => true];
        }

        $this->marketMemberships()->whereIn('market', $toRemove)->delete();
        $this->unsetRelation('marketMemberships');
        $this->refreshPrimaryMarket();

        return ['removed' => $toRemove, 'refused_last' => false];
    }

    /**
     * Replaces the contact's markets outright — what a "move" means when no
     * source market is named. Passing an empty array is a no-op rather than
     * orphaning the contact, for the same reason removeMarkets() guards.
     *
     * @param  array<int, string>  $markets  already-normalized market keys
     */
    public function syncMarkets(array $markets): void
    {
        $markets = array_values(array_unique(array_filter($markets)));

        if (empty($markets)) {
            return;
        }

        if (! self::supportsMultipleMarkets()) {
            $this->update(['market' => $markets[0]]);

            return;
        }

        $this->marketMemberships()->whereNotIn('market', $markets)->delete();
        $this->unsetRelation('marketMemberships');
        $this->addMarkets($markets);
    }

    /**
     * Keeps `marketing_contacts.market` — the primary market, and the value
     * every pre-existing API response and DB query already reads — pointing at
     * a market the contact genuinely belongs to. The current value is preserved
     * whenever it's still a real membership, so a contact's primary market
     * never shifts just because another market was added alongside it.
     */
    public function refreshPrimaryMarket(): void
    {
        if (! self::supportsMultipleMarkets()) {
            return;
        }

        $held = $this->marketNames();

        if (in_array($this->market, $held, true)) {
            return;
        }

        $primary = $held[0] ?? null;

        if ($this->market !== $primary) {
            $this->update(['market' => $primary]);
        }
    }
}
