<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * One item on the shared team to-do list. Everyone sees the list; the people
 * an item concerns — its creator and its assignee — move it.
 */
class Todo extends Model
{
    private static ?bool $available = null;

    public const PRIORITIES = ['low', 'normal', 'high'];

    public const STATUSES = ['open', 'in_progress', 'done'];

    public const STATUS_LABELS = [
        'open'        => 'Open',
        'in_progress' => 'In progress',
        'done'        => 'Done',
    ];

    protected $fillable = [
        'title',
        'details',
        'due_on',
        'priority',
        'status',
        'assigned_admin_id',
        'created_by',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'due_on'       => 'date:Y-m-d',
        'completed_at' => 'datetime',
    ];

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('todos');
    }

    public static function forgetAvailableCheck(): void
    {
        self::$available = null;
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'completed_by');
    }

    /** The people an item concerns are the people who may move it. */
    public function isParticipant(AdminUser $user): bool
    {
        return $this->created_by === $user->id
            || $this->assigned_admin_id === $user->id
            || $user->role === 'super_admin';
    }
}
