<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * One line of the Zusammenfassende Meldung: an EU customer (country + VAT ID
 * + transaction type) within a reporting period. What BZSt receives is this
 * group's aggregate; what an auditor asks for is the lines inside it.
 *
 * The total is never stored — always the sum of the lines.
 */
class EcInvoiceGroup extends Model
{
    use RecordsStaffActivity;

    private static ?bool $available = null;

    public const TYPE_GOODS      = 'goods';
    public const TYPE_SERVICES   = 'services';
    public const TYPE_TRIANGULAR = 'triangular';
    public const TYPE_EXPORT     = 'export';

    public const TYPES = [
        self::TYPE_GOODS,
        self::TYPE_SERVICES,
        self::TYPE_TRIANGULAR,
        self::TYPE_EXPORT,
    ];

    /** Rendered labels, keyed by type. */
    public const TYPE_LABELS = [
        self::TYPE_GOODS      => 'Goods (Warenlieferung)',
        self::TYPE_SERVICES   => 'Services (Reverse-Charge)',
        self::TYPE_TRIANGULAR => 'Triangular Trade (Dreiecksgeschäft)',
        self::TYPE_EXPORT     => 'Export (Drittland / non-EU)',
    ];

    /**
     * § 18a Art: L = Lieferung, S = sonstige Leistung, D = Dreiecksgeschäft.
     *
     * `export` is deliberately ABSENT — a third-country export (§ 4 Nr. 1a /
     * § 6 UStG) is not an intra-Community supply and has no ZM line. Export
     * groups live in the list and the CSV audit file (the invoice + delivery
     * proof columns are exactly the Ausfuhr evidence), but the ELSTER XML
     * must exclude them or the filing is wrong. This constant's keys are
     * what the XML builder iterates, so absence IS the exclusion.
     */
    public const TYPE_ART = [
        self::TYPE_GOODS      => 'L',
        self::TYPE_SERVICES   => 'S',
        self::TYPE_TRIANGULAR => 'D',
    ];

    /**
     * The 27 EU member states a ZM line can name. Only intra-EU types are
     * held to this list — an export group names any third country.
     */
    public const COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
        'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public function isExport(): bool
    {
        return $this->transaction_type === self::TYPE_EXPORT;
    }

    protected $fillable = [
        'period',
        'country_code',
        'customer_vat_id',
        'transaction_type',
        'created_by',
    ];

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('ec_invoice_groups')
            && Schema::hasTable('ec_invoice_lines')
            && Schema::hasTable('ec_invoice_periods');
    }

    public static function forgetAvailableCheck(): void
    {
        self::$available = null;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EcInvoiceLine::class, 'group_id')->orderBy('invoice_date')->orderBy('id');
    }

    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromEcInvoiceGroup($this);
    }
}
