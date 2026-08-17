<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use RecordsStaffActivity;

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

    /** Uploading is real work, and `uploaded_by` has recorded it all along. */
    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromMedia($this);
    }
}
