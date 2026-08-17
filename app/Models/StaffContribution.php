<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Work the API could not see, entered by the person who did it.
 *
 * The social media post, the supplier call, the trade fair, the warehouse
 * audit, the training course. Currently invisible, and currently the reason
 * good people feel unseen — the ledger would otherwise reward whoever happens
 * to work inside the admin panel all day.
 *
 * Self-reported and labelled as such, everywhere, permanently. A verified entry
 * is still a self-reported entry that a manager agreed with; it never becomes
 * the same kind of fact as a StaffActivity row, and nothing sums the two.
 */
class StaffContribution extends Model
{
    public const CATEGORIES = [
        'social_media',
        'supplier',
        'customer_visit',
        'trade_fair',
        'training',
        'internal',
        // Design and technical work that leaves no commit — an architecture
        // decision, a spec, a session pairing on someone else's bug.
        'development',
        'other',
    ];

    public const CATEGORY_LABELS = [
        'social_media'   => 'Social media & content',
        'supplier'       => 'Supplier relations',
        'customer_visit' => 'Customer visit or call',
        'trade_fair'     => 'Trade fair & events',
        'training'       => 'Training & learning',
        'internal'       => 'Internal & admin',
        'development'    => 'Development & technical',
        'other'          => 'Other',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_VERIFIED, self::STATUS_REJECTED];

    protected $fillable = [
        'admin_user_id',
        'category',
        'title',
        'description',
        'performed_on',
        'minutes',
        'link',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $hidden = [
        // Internal detail. The file is served through a download route that
        // checks who is asking first.
        'file_path',
    ];

    protected $casts = [
        'performed_on' => 'date',
        'reviewed_at'  => 'datetime',
        'minutes'      => 'integer',
        'file_size'    => 'integer',
    ];

    private static ?bool $ready = null;

    public static function logAvailable(): bool
    {
        return self::$ready ??= Schema::hasTable('staff_contributions');
    }

    /** Test seam — the harness builds the table after the container boots. */
    public static function forgetLogCheck(): void
    {
        self::$ready = null;
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function scopeForAdmin(Builder $query, int $adminUserId): Builder
    {
        return $query->where('admin_user_id', $adminUserId);
    }

    /**
     * `whereDate` rather than `whereBetween`, because the column is a date but
     * arrives back from some drivers with a time on it — and '2026-08-17
     * 00:00:00' sorts after the string '2026-08-17', so a plain BETWEEN
     * silently drops everything logged on the last day of the range. Caught by
     * a test that asked for today's entry and got nothing.
     */
    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('performed_on', '>=', $from)
            ->whereDate('performed_on', '<=', $to);
    }

    /**
     * Editable only while nobody has ruled on it. Once a manager has verified
     * an entry, changing its wording would change what they agreed to — so the
     * route refuses, and the person adds a correcting entry instead.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function hasFile(): bool
    {
        return (bool) $this->getRawOriginal('file_path');
    }

    /**
     * Whether anything backs this entry up. Not enforced on create — a supplier
     * phone call has no artifact and refusing to record it would only mean it
     * goes unrecorded — but shown, so a reviewer can see what they are agreeing
     * to.
     */
    public function hasEvidence(): bool
    {
        return $this->hasFile() || ! empty($this->link);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? ucfirst($this->category);
    }
}
