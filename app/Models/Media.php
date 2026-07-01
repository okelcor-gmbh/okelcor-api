<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'filename',
        'original_name',
        'path',
        'url',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'alt_text',
        'collection',
        'uploaded_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function uploader()
    {
        return $this->belongsTo(AdminUser::class, 'uploaded_by')->withDefault();
    }
}
