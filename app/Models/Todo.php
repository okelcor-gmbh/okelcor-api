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

    /**
     * The people an item concerns are the people who may move it — and that
     * includes the rest of the department that raised it (Session 110).
     *
     * Finance is two people. Only the creator and the assignee could edit, so
     * the second finance user was locked out of his own team's requests and
     * shown a message naming two colleagues. Departments cover for each other;
     * the rule now says so.
     */
    public function isParticipant(AdminUser $user): bool
    {
        return $this->created_by === $user->id
            || $this->assigned_admin_id === $user->id
            || $this->sharesDepartmentWith($user)
            || $user->role === 'super_admin';
    }

    /**
     * Deleting stays narrower than editing by one person: the ASSIGNEE is
     * deliberately absent. They mark an item done; they do not erase that it
     * was asked. The department is included because clearing up after a
     * colleague is the same job as doing their work.
     */
    public function mayBeDeletedBy(AdminUser $user): bool
    {
        return $this->created_by === $user->id
            || $this->sharesDepartmentWith($user)
            || $user->role === 'super_admin';
    }

    /**
     * Compares the department STAMPED on the to-do against the viewer's
     * CURRENT one. Both must resolve: an unstamped row (the column not yet
     * migrated, or a creator that cannot be resolved) has no department, and
     * two nulls must never read as a match — that would open every such row
     * to everybody.
     */
    public function sharesDepartmentWith(AdminUser $user): bool
    {
        $raised = $this->department();
        $viewer = AdminPermissions::departmentFor($user->role);

        return $raised !== null && $viewer !== null && $raised === $viewer;
    }
}
