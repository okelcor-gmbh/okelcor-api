<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The price of one FET tier — what we pay, and what the customer pays.
 *
 * Four rows, one per tier. The engine table's `fet_model` strings carry an SAE
 * size that has nothing to do with price, so the tier is resolved from the
 * string rather than matched against it.
 */
class FetPrice extends Model
{
    private static ?bool $available = null;

    public const TIERS = ['PRO_FI', 'PRO_FII', 'PRO_FIII', 'PRO_FIV'];

    protected $fillable = ['tier', 'label', 'cost_price', 'price', 'currency', 'updated_by'];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'price'      => 'decimal:2',
    ];

    /**
     * `cost_price` is what we pay the supplier. It is hidden by default so
     * that a careless `toJson()` on a public route cannot leak it; the admin
     * endpoint asks for it explicitly via `adminRows()`.
     */
    protected $hidden = ['cost_price'];

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('fet_prices');
    }

    /** Test seam. */
    public static function forgetAvailableCheck(): void
    {
        self::$available = null;
    }

    /**
     * Which tier a `fet_model` string belongs to.
     *
     * "FET-PRO-FIII (SAE 1/2\" or 5/8\")" → PRO_FIII. The alternation is
     * ordered longest-first on purpose: matching `I` before `III` would file
     * every tier as PRO_FI and silently price a 1,450 unit at 250.
     */
    public static function tierFor(?string $fetModel): ?string
    {
        if (! is_string($fetModel) || $fetModel === '') {
            return null;
        }

        if (preg_match('/PRO[-\s]?F(IV|III|II|I)\b/i', $fetModel, $m) !== 1) {
            return null;
        }

        return 'PRO_F' . strtoupper($m[1]);
    }

    /**
     * Tier => public price. **Retail and currency only — never cost.**
     *
     * The public FET endpoint is unauthenticated and returns models wholesale,
     * so the guarantee that our supplier costs stay private is structural: the
     * public path has no method that can hand them over.
     *
     * @return array<string, array{price: string|null, currency: string}>
     */
    public static function publicMap(): array
    {
        if (! self::available()) {
            return [];
        }

        return self::query()
            ->get(['tier', 'price', 'currency'])
            ->mapWithKeys(fn (self $r) => [$r->tier => [
                'price'    => $r->price === null ? null : (string) $r->price,
                'currency' => $r->currency ?: 'EUR',
            ]])
            ->all();
    }

    /**
     * Every column, cost included. Admin only — call this from a route behind
     * `permission:fet.pricing` and nowhere else.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminRows(): array
    {
        if (! self::available()) {
            return [];
        }

        // Sorted in PHP against TIERS rather than with MySQL's FIELD(), which
        // the sqlite test harness cannot run — the trap this project keeps
        // paying for in the other direction.
        return self::query()
            ->get()
            ->sortBy(fn (self $r) => array_search($r->tier, self::TIERS, true))
            ->values()
            ->map(fn (self $r) => [
                'id'         => $r->id,
                'tier'       => $r->tier,
                'label'      => $r->label,
                'cost_price' => $r->cost_price === null ? null : (string) $r->cost_price,
                'price'      => $r->price === null ? null : (string) $r->price,
                'currency'   => $r->currency ?: 'EUR',
                // What finance actually wants to see when setting a price.
                'margin'     => ($r->price === null || $r->cost_price === null)
                    ? null
                    : number_format((float) $r->price - (float) $r->cost_price, 2, '.', ''),
                'updated_at' => $r->updated_at?->toIso8601String(),
            ])
            ->all();
    }
}
