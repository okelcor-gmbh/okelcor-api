<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice as the finance system (sevDesk) has it, typed in by finance.
 *
 * Exists to be compared against `invoices` — the ones this API raised. The
 * number anyone acts on is the difference between the two counts, not either
 * count on its own.
 */
class FinanceInvoice extends Model
{
    use RecordsStaffActivity;

    /**
     * Where a register row came from.
     *
     *   sevdesk — finance typed it in from the finance system
     *   okelcor — this API registered it for an invoice it produced, or one an
     *             order manager issued to a customer (see InvoiceRegistrar)
     *   upload  — an invoice document attached by an admin from neither
     *   other   — anything else, kept from the original two-value list
     */
    public const SYSTEMS = ['sevdesk', 'okelcor', 'upload', 'other'];

    /** Systems a person may create by hand. `okelcor` rows are written for them. */
    public const MANUAL_SYSTEMS = ['sevdesk', 'upload', 'other'];
    public const CHANNELS = ['normal', 'ebay'];

    /**
     * Which side of an order's profit a row sits on.
     *
     *   register — a reconciliation entry, the table's original job
     *   revenue  — the customer-agreed invoice for an order; its amount is the
     *              order's revenue once finalized
     *   supplier — a supplier's invoice against the order: a cost
     */
    public const ROLE_REGISTER = 'register';
    public const ROLE_REVENUE  = 'revenue';
    public const ROLE_SUPPLIER = 'supplier';
    public const ROLES = [self::ROLE_REGISTER, self::ROLE_REVENUE, self::ROLE_SUPPLIER];

    protected $fillable = [
        'system',
        'external_number',
        'order_ref',
        'invoice_number',
        'amount',
        'currency',
        'issued_on',
        'channel',
        'role',
        'supplier_name',
        'finalized_at',
        'finalized_by',
        'notes',
        'recorded_by',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'uploaded_at',
        'source_type',
        'source_id',
    ];

    protected $hidden = [
        // The stored path is an internal detail; the file is served through a
        // download route that checks the caller's permission first.
        'file_path',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'issued_on'    => 'date',
        'uploaded_at'  => 'datetime',
        'finalized_at' => 'datetime',
        'file_size'    => 'integer',
    ];

    /**
     * Whether the register's file/origin columns exist yet.
     *
     * Memoised per process. Invoice registration runs off model events on the
     * money path, so between the code deploying and the migration running,
     * raising an invoice has to keep working — a reporting table must never be
     * able to fail the thing it reports on.
     */
    private static ?bool $registerReady = null;

    public static function registerAvailable(): bool
    {
        return self::$registerReady ??= Schema::hasTable('finance_invoices')
            && Schema::hasColumn('finance_invoices', 'source_type');
    }

    /** Test seam — the harness builds the table after the container boots. */
    public static function forgetRegisterCheck(): void
    {
        self::$registerReady = null;
        self::$rolesReady    = null;
    }

    /**
     * Whether the role/finalization columns exist yet — same deploy-order
     * story as the register columns: recording an invoice must keep working
     * between this code shipping and its migration running.
     */
    private static ?bool $rolesReady = null;

    public static function rolesAvailable(): bool
    {
        return self::$rolesReady ??= Schema::hasTable('finance_invoices')
            && Schema::hasColumn('finance_invoices', 'role');
    }

    /** Written for the operator rather than by them. */
    public function isAutoRegistered(): bool
    {
        return $this->system === 'okelcor';
    }

    public function hasFile(): bool
    {
        return (bool) $this->getRawOriginal('file_path');
    }

    /** Customer has agreed to it; the money fields are locked from here. */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /** The rows profitability counts as an order's revenue. */
    public function scopeFinalizedRevenue($query)
    {
        return $query->where('role', self::ROLE_REVENUE)->whereNotNull('finalized_at');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recorded_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'finalized_by');
    }

    /**
     * The order this was raised against, if it names one this system knows.
     *
     * Deliberately a lookup rather than a constraint: an entry naming an order
     * that does not exist here is precisely the discrepancy worth seeing, so it
     * has to be storable.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_ref', 'ref');
    }

    /**
     * Only rows a person typed. The registrar writes `okelcor` rows for this
     * system's own invoices, and crediting finance with those would count the
     * same work twice — once here, and once through the order log that raised
     * the invoice.
     */
    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromFinanceInvoice($this);
    }
}
