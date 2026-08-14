<?php

namespace App\Models;

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

    protected $fillable = [
        'system',
        'external_number',
        'order_ref',
        'invoice_number',
        'amount',
        'currency',
        'issued_on',
        'channel',
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
        'amount'      => 'decimal:2',
        'issued_on'   => 'date',
        'uploaded_at' => 'datetime',
        'file_size'   => 'integer',
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recorded_by');
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
}
