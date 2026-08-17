<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\StaffActivity;
use App\Models\StaffContribution;
use Illuminate\Support\Facades\DB;

/**
 * The team-wide view of the contribution ledger.
 *
 * Descriptive, and it stays that way. It counts what each person did and shows
 * it beside everyone else's; it does not score, rank, weight or total people
 * against each other. That restraint is not squeamishness — a count of ledger
 * rows is not a measure of value, and one order manager's month can be sixty
 * documents while another's is one container negotiation that took three weeks.
 * Presenting either as a league table would be a claim the data cannot support.
 *
 * People are grouped by **job title**, never by role. `admin_users.role` is a
 * permission set: two order managers and the person running operations all hold
 * `admin`, because all three need customers, campaigns and quote requests.
 * Grouping by role would file the three of them under "Admin" and describe none
 * of them.
 *
 * Recorded and self-reported work are returned as separate figures with no
 * combined total, exactly as the per-person endpoints do.
 */
class StaffReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $from, string $to, bool $includeInactive = false): array
    {
        $people = AdminUser::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        $recorded = $this->recordedTotals($from, $to);
        $logged   = $this->contributionTotals($from, $to);

        $rows = $people->map(function (AdminUser $person) use ($recorded, $logged) {
            $mine = $recorded[$person->id] ?? [];

            return [
                'admin_user_id' => $person->id,
                'name'          => $person->name,
                'email'         => $person->email,

                // The job, first and prominent. The role is carried too, but as
                // access information rather than as a description of anyone.
                'job_title'     => $person->jobTitle(),
                'job_title_set' => $person->hasJobTitle(),
                'role'          => $person->role,

                'recorded' => [
                    'total'       => (int) array_sum($mine),
                    'by_category' => collect(StaffActivity::CATEGORIES)->map(fn (string $c) => [
                        'category' => $c,
                        'label'    => StaffActivity::CATEGORY_LABELS[$c],
                        'total'    => (int) ($mine[$c] ?? 0),
                    ])->values()->all(),
                ],

                'self_reported' => $logged[$person->id] ?? [
                    'total' => 0, 'verified' => 0, 'pending' => 0, 'rejected' => 0,
                ],
            ];
        })->values()->all();

        return [
            'from' => $from,
            'to'   => $to,

            'people' => $rows,

            'totals' => [
                'people'                 => count($rows),
                'people_with_activity'   => count(array_filter($rows, fn ($r) => $r['recorded']['total'] > 0)),
                'recorded'               => array_sum(array_map(fn ($r) => $r['recorded']['total'], $rows)),
                'self_reported'          => array_sum(array_map(fn ($r) => $r['self_reported']['total'], $rows)),
                'awaiting_review'        => array_sum(array_map(fn ($r) => $r['self_reported']['pending'], $rows)),
            ],

            // Everything a reader needs in order not to misread the table,
            // travelling with the payload rather than living on the page that
            // rendered it once. This report gets exported and forwarded.
            'caveats' => [
                'Counts are of recorded actions, not of value. One person\'s month can be sixty '
                    . 'documents while another\'s is a single container negotiation that took three weeks.',
                'Recorded and self-reported work are separate figures and are never added together.',
                'Nobody is ranked or scored here. Ordering is alphabetical.',
                'People are grouped by job title, not by system role — the role only says what '
                    . 'someone is allowed to open.',
            ],
        ];
    }

    /**
     * @return array<int, array<string, int>>  admin id → category → count
     */
    private function recordedTotals(string $from, string $to): array
    {
        if (! StaffActivity::ledgerAvailable()) {
            return [];
        }

        return StaffActivity::query()
            ->whereBetween('occurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereNotNull('admin_user_id')
            ->select('admin_user_id', 'category', DB::raw('COUNT(*) as total'))
            ->groupBy('admin_user_id', 'category')
            ->get()
            ->groupBy('admin_user_id')
            ->map(fn ($rows) => $rows->pluck('total', 'category')->map(fn ($n) => (int) $n)->all())
            ->all();
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function contributionTotals(string $from, string $to): array
    {
        if (! StaffContribution::logAvailable()) {
            return [];
        }

        return StaffContribution::query()
            ->whereDate('performed_on', '>=', $from)
            ->whereDate('performed_on', '<=', $to)
            ->get(['admin_user_id', 'status'])
            ->groupBy('admin_user_id')
            ->map(fn ($rows) => [
                'total'    => $rows->count(),
                'verified' => $rows->where('status', StaffContribution::STATUS_VERIFIED)->count(),
                'pending'  => $rows->where('status', StaffContribution::STATUS_PENDING)->count(),
                'rejected' => $rows->where('status', StaffContribution::STATUS_REJECTED)->count(),
            ])
            ->all();
    }
}
