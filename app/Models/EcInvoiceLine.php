<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One invoice inside a ZM group — the itemization behind the aggregate BZSt
 * receives. Carries the invoice PDF, the delivery proof, and the assignee
 * chasing whichever of the two is still missing.
 */
class EcInvoiceLine extends Model
{
    use RecordsStaffActivity;

    public const STATUS_COMPLETE    = 'complete';
    public const STATUS_PENDING_DOC = 'pending_doc';
    public const STATUS_REVIEW      = 'review';

    public const STATUSES = [
        self::STATUS_COMPLETE,
        self::STATUS_PENDING_DOC,
        self::STATUS_REVIEW,
    ];

    public const STATUS_LABELS = [
        self::STATUS_COMPLETE    => 'Verified',
        self::STATUS_PENDING_DOC => 'Pending Proof',
        self::STATUS_REVIEW      => 'In Review',
    ];

    protected $fillable = [
        'group_id',
        'invoice_number',
        'invoice_date',
        'amount',
        'assigned_admin_id',
        'person_name',
        'task_status',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'uploaded_at',
        'proof_path',
        'proof_original_filename',
        'proof_mime_type',
        'proof_file_size',
        'proof_uploaded_at',
        'created_by',
    ];

    protected $hidden = [
        // Storage paths are server internals — the API exposes has_* flags
        // and download routes, same rule as every other finance upload.
        'file_path',
        'proof_path',
    ];

    protected $casts = [
        'invoice_date'      => 'date:Y-m-d',
        'amount'            => 'decimal:2',
        'uploaded_at'       => 'datetime',
        'proof_uploaded_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(EcInvoiceGroup::class, 'group_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function hasInvoiceFile(): bool
    {
        return $this->getRawOriginal('file_path') !== null;
    }

    public function hasProofFile(): bool
    {
        return $this->getRawOriginal('proof_path') !== null;
    }

    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromEcInvoiceLine($this);
    }
}
