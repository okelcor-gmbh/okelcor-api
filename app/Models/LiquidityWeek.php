<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * One ISO week of the liquidity ladder — the bank balance and expected
 * movements finance maintains for the current week and the three ahead.
 *
 * Keyed by the same 'o-\WW' format the operations report buckets by
 * ('2026-W35'), so the two features cannot disagree about which week a date
 * falls in. The rolling four-week window is entirely a read-time concern —
 * see AdminLiquidityController; a week that has ended keeps its row as
 * history and simply falls out of the view.
 */
class LiquidityWeek extends Model
{
    use RecordsStaffActivity;

    private static ?bool $available = null;

    protected $fillable = [
        'week_key',
        'iso_year',
        'iso_week',
        'starts_on',
        'ends_on',
        'bank_balance',
        'expected_in',
        'expected_out',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'starts_on'    => 'date',
        'ends_on'      => 'date',
        'bank_balance' => 'decimal:2',
        'expected_in'  => 'decimal:2',
        'expected_out' => 'decimal:2',
    ];

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('liquidity_weeks');
    }

    public static function forgetAvailableCheck(): void
    {
        self::$available = null;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    /** '2026-W35' for any date — one definition, shared with the planner. */
    public static function keyFor(CarbonInterface $date): string
    {
        return $date->format('o-\WW');
    }

    /**
     * The Monday a key names, or null when the key is not a real ISO week
     * ('2026-W54' parses nowhere).
     *
     * @return array{key: string, year: int, week: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public static function parseKey(string $key): ?array
    {
        if (! preg_match('/^(\d{4})-W(\d{2})$/', $key, $m)) {
            return null;
        }

        $year = (int) $m[1];
        $week = (int) $m[2];

        if ($week < 1 || $week > 53) {
            return null;
        }

        $start = CarbonImmutable::now()->setISODate($year, $week)->startOfWeek();

        // setISODate silently rolls week 53 into the next year when the year
        // has only 52 — reject rather than store a row under a key that names
        // a different week than the one it lands in.
        if (self::keyFor($start) !== $key) {
            return null;
        }

        return [
            'key'   => $key,
            'year'  => $year,
            'week'  => $week,
            'start' => $start,
            'end'   => $start->endOfWeek(),
        ];
    }

    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromLiquidityWeek($this);
    }
}
