<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Todo;
use App\Services\AdminNotificationService;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The shared team to-do list. Behind `staff.self` — every role holds it,
 * because the ask was that ANYONE can use it and tag a teammate. Who may
 * move an item is decided here, not by role: its creator, its assignee, or
 * super_admin.
 *
 * Tagging someone notifies them (one deduped nudge per day, like every other
 * tag in this panel) and the item lands in their My Work; closing a tagged
 * item tells whoever created it, so "done" travels back without a message
 * being written.
 */
class AdminTodoController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/todos — staff.self
    //
    // ?scope=all|mine|created  ·  ?status=open|in_progress|done|active
    // "active" (the default) is open + in_progress — the list people work
    // from; done items stay reachable, not in the way.
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'scope'      => ['nullable', 'in:all,mine,created'],
            'status'     => ['nullable', 'in:active,open,in_progress,done,all'],
            'q'          => ['nullable', 'string', 'max:100'],
            // The department NAME, not the role — it is what the chip shows,
            // and several roles share one, so filtering on the role would
            // silently drop half of "from Management".
            'department' => ['nullable', 'string', 'max:40'],
        ]);

        if (! Todo::available()) {
            return response()->json([
                'data'    => [],
                'meta'    => ['todos_available' => false],
                'message' => 'The to-do list is not available yet — the database migration has not run.',
            ]);
        }

        $user  = $request->user();
        $query = Todo::with([
            'assignee:id,name,display_name',
            'creator:id,name,display_name',
            'completedBy:id,name,display_name',
        ]);

        match ($filters['scope'] ?? 'all') {
            'mine'    => $query->where('assigned_admin_id', $user->id),
            'created' => $query->where('created_by', $user->id),
            default   => null,
        };

        match ($filters['status'] ?? 'active') {
            'all'    => null,
            'active' => $query->whereIn('status', ['open', 'in_progress']),
            default  => $query->where('status', $filters['status']),
        };

        if (! empty($filters['department']) && Todo::supportsSource()) {
            $roles = array_keys(array_filter(
                AdminPermissions::DEPARTMENTS,
                fn ($d) => $d === $filters['department'],
            ));

            // An unrecognised department matches nothing rather than
            // everything — a filter that silently stops filtering is worse
            // than one that visibly returns nothing.
            $query->whereIn('created_by_role', $roles ?: ['__none__']);
        }

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(fn ($sub) => $sub->where('title', 'like', "%{$q}%")
                ->orWhere('details', 'like', "%{$q}%"));
        }

        // Undated items sink below dated ones; done items sort by recency.
        $todos = $query
            ->orderByRaw("CASE WHEN status = 'done' THEN 1 ELSE 0 END")
            ->orderByRaw('due_on IS NULL, due_on')
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $todos->map(fn (Todo $t) => $this->format($t, $user))->values(),
            'meta' => [
                'todos_available' => true,
                'priorities' => Todo::PRIORITIES,
                'statuses'   => collect(Todo::STATUS_LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'open_count' => Todo::whereIn('status', ['open', 'in_progress'])->count(),
                // Only departments that actually have to-dos: a filter
                // offering eight choices where six return nothing is a
                // worse list than one offering the two that exist.
                'departments' => Todo::supportsSource()
                    ? Todo::query()
                        ->selectRaw('created_by_role, COUNT(*) as aggregate')
                        ->whereNotNull('created_by_role')
                        ->groupBy('created_by_role')
                        ->pluck('aggregate', 'created_by_role')
                        ->reduce(function (array $carry, $count, $role) {
                            $name = AdminPermissions::departmentFor($role);
                            $carry[$name] = ($carry[$name] ?? 0) + (int) $count;

                            return $carry;
                        }, [])
                    : [],
                // The tag picker — tagging is how an item reaches someone's
                // My Work and notifies them.
                'staff'      => AdminUser::where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'display_name'])
                    ->map(fn ($a) => ['id' => $a->id, 'name' => trim($a->display_name ?: $a->name)])
                    ->values(),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/todos — staff.self
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        if (! Todo::available()) {
            return response()->json([
                'message' => 'The to-do list is not available yet — the database migration has not run.',
            ], 503);
        }

        $data = $request->validate([
            'title'             => ['required', 'string', 'max:200'],
            'details'           => ['nullable', 'string', 'max:2000'],
            'due_on'            => ['nullable', 'date'],
            'priority'          => ['nullable', Rule::in(Todo::PRIORITIES)],
            'assigned_admin_id' => ['nullable', 'integer', 'exists:admin_users,id'],
        ]);

        $todo = Todo::create([
            'title'             => $data['title'],
            'details'           => $data['details'] ?? null,
            'due_on'            => $data['due_on'] ?? null,
            'priority'          => $data['priority'] ?? 'normal',
            'assigned_admin_id' => $data['assigned_admin_id'] ?? null,
            'created_by'        => $request->user()?->id,
            // Stamped now, not derived later: `created_by` is nullOnDelete
            // and people change role, so a label computed at read time would
            // lose or rewrite the origin. See the migration.
            ...(Todo::supportsSource() ? ['created_by_role' => $request->user()?->role] : []),
        ]);

        $this->notifyAssignee($todo, $request->user());

        return response()->json([
            'data'    => $this->format($todo->fresh(['assignee:id,name,display_name', 'creator:id,name,display_name']), $request->user()),
            'message' => 'To-do added.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/todos/{id} — staff.self, participants only
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $todo = Todo::findOrFail($id);
        $user = $request->user();

        if (! $todo->isParticipant($user)) {
            return response()->json([
                'message' => $todo->department()
                    ? "Only {$todo->department()}, the person this to-do is tagged to, or whoever created it, can change it."
                    : 'Only the person this to-do is tagged to, or whoever created it, can change it.',
            ], 403);
        }

        $data = $request->validate([
            'title'             => ['sometimes', 'string', 'max:200'],
            'details'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'assignee_note'     => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_on'            => ['sometimes', 'nullable', 'date'],
            'priority'          => ['sometimes', Rule::in(Todo::PRIORITIES)],
            'status'            => ['sometimes', Rule::in(Todo::STATUSES)],
            'assigned_admin_id' => ['sometimes', 'nullable', 'integer', 'exists:admin_users,id'],
        ]);

        // Deploy-order safety: the note column can arrive after this code
        // does. Drop the field rather than failing the update — the status
        // is the load-bearing half and must still travel.
        if (array_key_exists('assignee_note', $data) && ! Todo::supportsAssigneeNote()) {
            unset($data['assignee_note']);
        }

        $previousAssignee = $todo->assigned_admin_id;
        $previousStatus   = $todo->status;
        $previousNote     = Todo::supportsAssigneeNote() ? $todo->assignee_note : null;

        $todo->fill($data);

        // The completion stamp follows the status in both directions — a
        // reopened item was NOT done, and keeping the stamp would say it was.
        if ($todo->status === 'done' && $previousStatus !== 'done') {
            $todo->completed_at = now();
            $todo->completed_by = $user->id;
        } elseif ($todo->status !== 'done') {
            $todo->completed_at = null;
            $todo->completed_by = null;
        }

        $todo->save();

        if ($todo->assigned_admin_id !== $previousAssignee) {
            $this->notifyAssignee($todo, $user);
        }

        $noteChanged = Todo::supportsAssigneeNote() && $todo->assignee_note !== $previousNote;
        $completed   = $todo->status === 'done' && $previousStatus !== 'done';

        // "Done" travels back to whoever asked, without a message written —
        // and so does the note, which is the half that carries the reason.
        // Both ride the same notification: whoever asked wants one nudge
        // saying what happened, not two saying half of it each.
        if (($completed || $noteChanged) && $todo->created_by && $todo->created_by !== $user->id) {
            try {
                AdminNotificationService::notifyUser(
                    adminUserId: $todo->created_by,
                    type: $completed ? 'todo_completed' : 'todo_note_added',
                    title: $completed
                        ? "{$user->name} completed: {$todo->title}"
                        : "{$user->name} left a note on: {$todo->title}",
                    body: $noteChanged ? $todo->assignee_note : null,
                    actionUrl: '/admin/todos?todo=' . $todo->id,
                    severity: $completed ? 'success' : 'info',
                    relatedType: 'todo',
                    relatedId: $todo->id,
                    // The completion is once per item; a note can be revised,
                    // and each revision is worth hearing, so it dedupes on
                    // the day rather than on the item alone.
                    dedupeKey: $completed
                        ? "todo_completed:{$todo->id}"
                        : "todo_note:{$todo->id}:" . now()->toDateString(),
                );
            } catch (\Throwable $e) {
                Log::warning('To-do update notification failed', ['todo' => $todo->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'data'    => $this->format($todo->fresh([
                'assignee:id,name,display_name', 'creator:id,name,display_name', 'completedBy:id,name,display_name',
            ]), $user),
            'message' => 'To-do updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/todos/{id} — staff.self, creator (or super_admin)
    // -------------------------------------------------------------------------
    public function destroy(Request $request, int $id): JsonResponse
    {
        $todo = Todo::findOrFail($id);
        $user = $request->user();

        // Narrower than editing by exactly one person: the assignee marks an
        // item done, they do not erase that it was asked. The department the
        // to-do came from may, because tidying up after a colleague is the
        // same job as doing their work.
        if (! $todo->mayBeDeletedBy($user)) {
            return response()->json([
                'message' => $todo->department()
                    ? "Only {$todo->department()}, or whoever created this to-do, can delete it — mark it done instead."
                    : 'Only whoever created a to-do can delete it — mark it done instead.',
            ], 403);
        }

        $todo->delete();

        return response()->json(['message' => 'To-do removed.']);
    }

    // -------------------------------------------------------------------------

    /**
     * One deduped nudge per assignee per day — the same tagging contract as
     * the finance snapshot and the EC invoice lines. Never self-notifies.
     */
    private function notifyAssignee(Todo $todo, ?AdminUser $actor): void
    {
        if (! $todo->assigned_admin_id || $todo->assigned_admin_id === $actor?->id) {
            return;
        }

        try {
            AdminNotificationService::notifyUser(
                adminUserId: $todo->assigned_admin_id,
                type: 'todo_assigned',
                title: 'A to-do was tagged to you',
                body: $todo->title . ' — open My Work or the To-Do list to see everything on your plate.',
                actionUrl: '/admin/todos?todo=' . $todo->id,
                severity: 'info',
                dedupeKey: 'todo_assigned:' . $todo->assigned_admin_id . ':' . now()->toDateString(),
            );
        } catch (\Throwable $e) {
            Log::warning('To-do assignee notification failed', ['todo' => $todo->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Todo $todo, ?AdminUser $viewer): array
    {
        $name = fn (?AdminUser $a) => $a ? trim($a->display_name ?: $a->name) : null;

        return [
            'id'                => $todo->id,
            'title'             => $todo->title,
            'details'           => $todo->details,
            // The brief and the reply are two different people talking, so
            // they travel as two fields rather than one edited in place.
            'assignee_note'     => Todo::supportsAssigneeNote() ? $todo->assignee_note : null,
            'due_on'            => $todo->due_on?->toDateString(),
            'overdue'           => $todo->due_on !== null && $todo->status !== 'done' && $todo->due_on->isPast(),
            'priority'          => $todo->priority,
            'status'            => $todo->status,
            'assigned_admin_id' => $todo->assigned_admin_id,
            'assignee'          => $name($todo->assignee),
            'created_by'        => $todo->created_by,
            'creator'           => $name($todo->creator),
            // Where it came from. The role is the stored fact; the department
            // is derived from it, so the wording can change without a data
            // migration.
            'created_by_role'   => Todo::supportsSource() ? $todo->created_by_role : null,
            'department'        => $todo->department(),
            'completed_at'      => $todo->completed_at?->toIso8601String(),
            'completed_by_name' => $name($todo->completedBy),
            'created_at'        => $todo->created_at?->toIso8601String(),
            // Served rather than re-derived client-side — the same-person
            // rules live here, and super_admin's reach is not visible from
            // the ids alone.
            'you_may_edit'      => $viewer !== null && $todo->isParticipant($viewer),
            'you_may_delete'    => $viewer !== null && $todo->mayBeDeletedBy($viewer),
        ];
    }
}
