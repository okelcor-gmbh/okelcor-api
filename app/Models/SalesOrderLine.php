<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One transaction line on a sales-board order: a customer line (revenue +
 * tyre quantity) or a supplier line (cost + the document that proves it).
 */
class SalesOrderLine extends Model
{
    public const PARTY_CUSTOMER = 'customer';
    public const PARTY_SUPPLIER = 'supplier';

    public const PARTY_TYPES = [
        self::PARTY_CUSTOMER,
        self::PARTY_SUPPLIER,
    ];

    protected $fillable = [
        'entry_id',
        'party_type',
        'party_name',
        'tyre_qty',
        'amount',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'uploaded_at',
        'created_by',
    ];

    protected $hidden = [
        'file_path',
    ];

    protected $casts = [
        'tyre_qty'    => 'integer',
        'amount'      => 'decimal:2',
        'uploaded_at' => 'datetime',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SalesOrderEntry::class, 'entry_id');
    }

    public function hasFile(): bool
    {
        return $this->getRawOriginal('file_path') !== null;
    }
}
