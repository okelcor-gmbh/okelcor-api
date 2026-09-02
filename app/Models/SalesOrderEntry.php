<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * One order on the Sales & Order Management board — a hand-entered record
 * whose customer lines (revenue) and supplier lines (costs) produce its
 * gross profit and its verification status, both computed, never stored.
 */
class SalesOrderEntry extends Model
{
    use RecordsStaffActivity;

    private static ?bool $available = null;

    public const SEGMENTS = ['B2B', 'B2C'];

    public const CATEGORIES = ['Tyres', 'FET'];

    protected $fillable = [
        'order_no',
        'customer_name',
        'segment',
        'period',
        'category',
        'created_by',
    ];

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('sales_order_entries')
            && Schema::hasTable('sales_order_lines');
    }

    public static function forgetAvailableCheck(): void
    {
        self::$available = null;
    }

    /** 'YYYY-MM' — the month shape the board files orders under. */
    public static function isValidPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'entry_id')->orderBy('id');
    }

    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromSalesOrderEntry($this);
    }
}
