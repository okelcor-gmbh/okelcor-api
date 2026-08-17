<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\StaffActivity;
use App\Models\StaffContribution;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Reading the contribution ledger.
 *
 * Two rules run through every endpoint here:
 *
 * Anyone may read their own record, always. That is not a convenience — it is
 * the promise that makes the whole feature acceptable to the people in it.
 * Reading somebody else's needs `staff.view_team`.
 *
 * Verified work and self-reported work are returned as separate figures and are
 * never added together. The summary carries both and a `note` saying why they
 * are apart, because a spreadsheet outlives the screen it came from and someone
 * will eventually try to sum that column.
 */
class AdminStaffLedgerController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/staff/activity — staff.self
    // -------------------------------------------------------------------------
    public function activity(Request $request): JsonResponse
    {
        $request->validate([
            'admin_user_id' => ['nullable', 'integer'],
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date'],
            'category'      => ['nullable', Rule::in(StaffActivity::CATEGORIES)],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $subject = $this->resolveSubject($request);

        if ($subject instanceof JsonResponse) {
            return $subject;
        }

        [$from, $to] = $this->range($request);

        $query = StaffActivity::query()
            ->forAdmin($subject->id)
            ->between($from, $to)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $paginated = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'data' => collect($paginated->items())->map(fn (StaffActivity $a) => [
                'id'             => $a->id,
                'category'       => $a->category,
                'category_label' => $a->categoryLabel(),
                'action'         => $a->action,
                'action_label'   => $a->actionLabel(),
                'subject_type'   => $a->subject_type,
                'subject_id'     => $a->subject_id,
                'subject_label'  => $a->subject_label,
                'occurred_at'    => $a->occurred_at?->toIso8601String(),
                'metadata'       => $a->metadata,
                // Stated on every row rather than assumed by the reader. The
                // distinction between this and a contribution is the point of
                // the feature.
                'verified'       => true,
            ])->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'admin_user'   => ['id' => $subject->id, 'name' => $subject->name, 'role' => $subject->role],
                'from'         => $from,
                'to'           => $to,
                'categories'   => StaffActivity::CATEGORY_LABELS,
                'is_self'      => $subject->id === $request->user()->id,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/staff/summary — staff.self
    //
    // Descriptive, not evaluative. It counts what happened; it does not score
    // it, rank it, or compare one person against another. Scoring is Phase 3
    // and needs a business decision about whether any of this touches pay
    // before it is safe to build.
    // -------------------------------------------------------------------------
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'admin_user_id' => ['nullable', 'integer'],
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date'],
        ]);

        $subject = $this->resolveSubject($request);

        if ($subject instanceof JsonResponse) {
            return $subject;
        }

        [$from, $to] = $this->range($request);

        $byCategory = StaffActivity::query()
            ->forAdmin($subject->id)
            ->between($from, $to)
            ->select('category', DB::raw('COUNT(*) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        // Every category present, empty ones as zero. "Nothing in marketing"
        // and "marketing is missing from this list" look identical when the
        // empty row is simply absent — the same choice the operations report
        // made for its axis.
        $categories = collect(StaffActivity::CATEGORIES)->map(fn (string $c) => [
            'category' => $c,
            'label'    => StaffActivity::CATEGORY_LABELS[$c],
            'total'    => (int) ($byCategory[$c] ?? 0),
        ])->values();

        $verifiedTotal = (int) $byCategory->sum();

        $topActions = StaffActivity::query()
            ->forAdmin($subject->id)
            ->between($from, $to)
            ->select('action', DB::raw('COUNT(*) as total'))
            ->groupBy('action')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'action' => $r->action,
                'label'  => ucfirst(str_replace('_', ' ', $r->action)),
                'total'  => (int) $r->total,
            ])->values();

        $contributions = $this->contributionSummary($subject->id, $from, $to);

        return response()->json([
            'data' => [
                'admin_user' => ['id' => $subject->id, 'name' => $subject->name, 'role' => $subject->role],
                'from'       => $from,
                'to'         => $to,

                // Deliberately two objects, not one total. Nothing in this
                // payload adds them together, and nothing downstream should.
                'recorded' => [
                    'total'       => $verifiedTotal,
                    'by_category' => $categories,
                    'top_actions' => $topActions,
                    'active_days' => $this->activeDays($subject->id, $from, $to),
                ],
                'self_reported' => $contributions,

                'note' => 'Recorded work is what the system watched happen. Self-reported work is entered by '
                    . 'the person and shown separately, verified or not. The two are never added together.',
            ],
            'meta'    => ['is_self' => $subject->id === $request->user()->id],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/staff/members — staff.self
    //
    // Who the caller may look at. Returns just themselves without
    // `staff.view_team`, so the frontend can render the same picker for
    // everyone rather than branching on a permission it would have to be told
    // about separately.
    // -------------------------------------------------------------------------
    public function members(Request $request): JsonResponse
    {
        $me = $request->user();

        $canViewTeam = AdminPermissions::can($me->role, 'staff.view_team');

        $query = AdminUser::query()->select('id', 'name', 'email', 'role', 'is_active')->orderBy('name');

        if (! $canViewTeam) {
            $query->whereKey($me->id);
        }

        return response()->json([
            'data' => $query->get()->map(fn (AdminUser $u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'role'      => $u->role,
                'is_active' => (bool) $u->is_active,
                'is_self'   => $u->id === $me->id,
            ])->values(),
            'meta' => [
                'can_view_team' => $canViewTeam,
                'can_verify'    => AdminPermissions::can($me->role, 'staff.verify'),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Whose record is being asked for, and whether the caller may have it.
     *
     * Defaults to the caller. Asking for somebody else needs `staff.view_team`,
     * and the refusal says which permission is missing rather than a bare 403 —
     * an admin panel that says "forbidden" with no reason generates a support
     * message every time.
     */
    private function resolveSubject(Request $request): AdminUser|JsonResponse
    {
        $me = $request->user();
        $id = $request->filled('admin_user_id') ? (int) $request->input('admin_user_id') : $me->id;

        if ($id === $me->id) {
            return $me;
        }

        if (! AdminPermissions::can($me->role, 'staff.view_team')) {
            return response()->json([
                'message' => 'You can see your own record. Viewing a colleague\'s needs the staff.view_team permission.',
                'code'    => 'staff_view_team_required',
            ], 403);
        }

        $subject = AdminUser::find($id);

        if ($subject === null) {
            return response()->json(['message' => 'That team member no longer exists.'], 404);
        }

        return $subject;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $to   = $request->filled('to') ? Carbon::parse($request->input('to')) : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : (clone $to)->subDays(29);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * Distinct days on which anything at all was recorded.
     *
     * Not a productivity measure and not presented as one — it answers "was
     * this a normal month or a fortnight of leave", which is the context a
     * count of anything is meaningless without.
     */
    private function activeDays(int $adminUserId, string $from, string $to): int
    {
        return StaffActivity::query()
            ->forAdmin($adminUserId)
            ->between($from, $to)
            ->select(DB::raw('DATE(occurred_at) as d'))
            ->distinct()
            ->get()
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function contributionSummary(int $adminUserId, string $from, string $to): array
    {
        if (! StaffContribution::logAvailable()) {
            return ['available' => false, 'total' => 0, 'verified' => 0, 'pending' => 0, 'rejected' => 0, 'by_category' => []];
        }

        $rows = StaffContribution::query()
            ->forAdmin($adminUserId)
            ->between($from, $to)
            ->get(['category', 'status']);

        return [
            'available'   => true,
            'total'       => $rows->count(),
            'verified'    => $rows->where('status', StaffContribution::STATUS_VERIFIED)->count(),
            'pending'     => $rows->where('status', StaffContribution::STATUS_PENDING)->count(),
            'rejected'    => $rows->where('status', StaffContribution::STATUS_REJECTED)->count(),
            'by_category' => collect(StaffContribution::CATEGORIES)->map(fn (string $c) => [
                'category' => $c,
                'label'    => StaffContribution::CATEGORY_LABELS[$c],
                'total'    => $rows->where('category', $c)->count(),
            ])->values(),
        ];
    }
}
