<?php

namespace App\Models;

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
    private static ?bool $available = null;

    public const TYPE_GOODS      = 'goods';
    public const TYPE_SERVICES   = 'services';
    public const TYPE_TRIANGULAR = 'triangular';

    public const TYPES = [
        self::TYPE_GOODS,
        self::TYPE_SERVICES,
        self::TYPE_TRIANGULAR,
    ];

    /** Rendered labels and the ELSTER Art code, keyed by type. */
    public const TYPE_LABELS = [
        self::TYPE_GOODS      => 'Goods (Warenlieferung)',
        self::TYPE_SERVICES   => 'Services (Reverse-Charge)',
        self::TYPE_TRIANGULAR => 'Triangular Trade (Dreiecksgeschäft)',
    ];

    /** § 18a Art: L = Lieferung, S = sonstige Leistung, D = Dreiecksgeschäft. */
    public const TYPE_ART = [
        self::TYPE_GOODS      => 'L',
        self::TYPE_SERVICES   => 'S',
        self::TYPE_TRIANGULAR => 'D',
    ];

    /** The 27 EU member states a ZM line can name. */
    public const COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
        'PT', 'RO', 'SE', 'SI', 'SK',
    ];

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
}
