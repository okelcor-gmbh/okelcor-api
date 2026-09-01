<?php

namespace App\Models;

use App\Support\AdminPermissions;
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

    private static ?bool $supportsAssigneeNote = null;

    private static ?bool $supportsSource = null;

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
        'assignee_note',
        'due_on',
        'priority',
        'status',
        'assigned_admin_id',
        'created_by',
        'created_by_role',
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

    /**
     * The note-back column arrived after the table did, so the feature can be
     * live ahead of its migration. Readers get null and writers drop the
     * field rather than failing the whole update — the status still travels.
     */
    public static function supportsAssigneeNote(): bool
    {
        return self::$supportsAssigneeNote ??= self::available()
            && Schema::hasColumn('todos', 'assignee_note');
    }

    /**
     * Same deploy-order contract as the note column: the stamp can arrive
     * after the code that writes it, and a to-do with no department badge is
     * a great deal better than one that cannot be created at all.
     */
    public static function supportsSource(): bool
    {
        return self::$supportsSource ??= self::available()
            && Schema::hasColumn('todos', 'created_by_role');
    }

    public static function forgetAvailableCheck(): void
    {
        self::$available = null;
        self::$supportsAssigneeNote = null;
        self::$supportsSource = null;
    }

    /**
     * Which part of the business raised this. Derived from the STAMPED role,
     * never from the creator's current one — see the migration for why.
     */
    public function department(): ?string
    {
        if (! self::supportsSource()) {
            return null;
        }

        return AdminPermissions::departmentFor($this->created_by_role);
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
