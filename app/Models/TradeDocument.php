<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeDocument extends Model
{
    protected $fillable = [
        'order_id',
        'order_ref',
        'type',
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
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'sent_at'   => 'datetime',
        'file_size' => 'integer',
    ];

    protected $hidden = [
        'pdf_path',
        'file_path',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'issued_by');
    }
}
