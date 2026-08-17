<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeDocument extends Model
{
    use RecordsStaffActivity;

    protected $fillable = [
        'order_id',
        'order_ref',
        'type',
        'type_label',
        'number',
        'pdf_path',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'status',
        'notes',
        'issued_by',
        'issued_at',
        'sent_at',
        'superseded_at',
        'superseded_by_id',
        'supersede_reason',
    ];

    protected $casts = [
        'issued_at'      => 'datetime',
        'sent_at'        => 'datetime',
        'superseded_at'  => 'datetime',
        'file_size'      => 'integer',
    ];

    protected $hidden = [
        'pdf_path',
        'file_path',
    ];

    /**
     * A commercial invoice or proforma issued to a customer is, to that
     * customer, an invoice — but it has no row in the `invoices` table, so it
     * appeared on neither side of the reconciliation while being exactly the
     * kind of document finance would have raised in sevDesk. Registering it
     * closes that gap.
     *
     * Voiding or superseding removes the register row: a document that was
     * withdrawn is not an invoice that was issued, and leaving it counted
     * invents a discrepancy rather than finding one.
     */
    protected static function booted(): void
    {
        static::saved(function (TradeDocument $document) {
            $registrar = app(\App\Services\InvoiceRegistrar::class);

            try {
                if (in_array($document->status, ['superseded', 'void', 'voided'], true)) {
                    $registrar->forget('trade_document', $document->id);

                    return;
                }

                $registrar->registerTradeDocument($document);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Trade document register hook failed', [
                    'document' => $document->number,
                    'error'    => $e->getMessage(),
                ]);
            }
        });

        static::deleted(function (TradeDocument $document) {
            try {
                app(\App\Services\InvoiceRegistrar::class)->forget('trade_document', $document->id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Trade document register cleanup failed', [
                    'document' => $document->number,
                    'error'    => $e->getMessage(),
                ]);
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'issued_by');
    }

    /**
     * Note the difference from the invoice register above: superseding a
     * document removes it from the register but leaves it in the ledger.
     * Withdrawing a document does not undo the work of having raised it, and a
     * month that quietly loses its entries because a customer asked for a
     * correction reads as though nothing was done.
     */
    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromTradeDocument($this);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'superseded_by_id');
    }
}
