<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autosaved campaign editor state. Disposable by design — deleted once the
 * campaign actually sends, and pruned per author so autosave cannot grow
 * without bound.
 */
class CampaignDraft extends Model
{
    protected $fillable = [
        'admin_user_id',
        'subject',
        'blocks',
        'theme',
        'body_html',
        'filters',
        'name',
    ];

    protected $casts = [
        'blocks'  => 'array',
        'theme'   => 'array',
        'filters' => 'array',
    ];

    /**
     * How many drafts one author may keep. Autosave means drafts are created
     * casually, so this is pruned on create rather than by a scheduled
     * command — nothing guarantees a scheduler runs on this host.
     */
    public const MAX_PER_AUTHOR = 20;

    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /**
     * Drop the oldest drafts beyond the per-author cap.
     */
    public static function pruneFor(int $adminUserId): void
    {
        // Tie-broken by id, not ordered by `updated_at` alone. Autosave writes
        // several rows within the same second routinely, and MySQL timestamps
        // have second resolution — ordering on the timestamp by itself makes
        // "keep the newest" non-deterministic, so a prune could discard the
        // draft the marketer is typing into right now.
        $keepIds = static::where('admin_user_id', $adminUserId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_PER_AUTHOR)
            ->pluck('id');

        static::where('admin_user_id', $adminUserId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /**
     * A human label for the restore list. A draft saved 20 seconds into
     * typing has no subject yet, and "Untitled" is more use than an empty row.
     */
    public function getLabelAttribute(): string
    {
        foreach ([$this->name, $this->subject] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Untitled campaign';
    }

    /**
     * Whether anything has actually been typed. The editor opening and
     * autosaving an empty canvas should not produce a "restore your work"
     * prompt that restores nothing.
     */
    public function isEmpty(): bool
    {
        return blank($this->subject)
            && blank($this->body_html)
            && blank($this->blocks)
            && blank($this->name);
    }
}
