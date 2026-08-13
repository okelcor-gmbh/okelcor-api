<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice as the finance system (sevDesk) has it, typed in by finance.
 *
 * Exists to be compared against `invoices` — the ones this API raised. The
 * number anyone acts on is the difference between the two counts, not either
 * count on its own.
 */
class FinanceInvoice extends Model
{
    public const SYSTEMS  = ['sevdesk', 'other'];
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
    ];

    protected $casts = [
        'amount'    => 'decimal:2',
        'issued_on' => 'date',
    ];

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
