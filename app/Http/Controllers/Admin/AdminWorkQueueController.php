<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerAccessRequest;
use App\Models\Customer;
use App\Models\EcInvoiceLine;
use App\Models\FinanceSnapshotItem;
use App\Models\Todo;
use App\Models\QuoteRequest;
use App\Services\AdminNotificationService;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM-3B — "My Work" queue.
 *
 * GET /admin/my-work — actionable work for the logged-in admin:
 *   assigned leads, due/overdue follow-ups, proposals awaiting conversion,
 *   plus (for customers.manage holders) pending approvals & access requests.
 *
 * Each item: { type, title, subtitle, priority, due_at, action_url, status }
 */
class AdminWorkQueueController extends Controller
{
    private const CLOSED_STATUSES = ['converted', 'closed', 'spam', 'rejected'];

    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $userId = $user->id;
        $now    = now();

        $canManageCustomers = AdminPermissions::can($user->role, 'customers.manage');

        $assignedLeads = QuoteRequest::where('assigned_to', $userId)
            ->whereNull('order_id')
            ->whereNotIn('qualification_status', self::CLOSED_STATUSES)
            ->orderByDesc('assigned_at')
            ->limit(100)
            ->get()
            ->map(fn (QuoteRequest $q) => [
                'type'       => 'assigned_lead',
                'title'      => $q->company_name ?: $q->full_name,
                'subtitle'   => "Lead {$q->ref_number}",
                'priority'   => $this->leadPriority($q),
                'due_at'     => $q->follow_up_at?->toIso8601String(),
                'action_url' => "/admin/quotes/{$q->id}",
                'status'     => $q->qualification_status ?? $q->status,
            ])->values();

        $dueFollowUps = QuoteRequest::where('assigned_to', $userId)
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', $now)
            ->whereNull('follow_up_completed_at')
            ->whereNotIn('qualification_status', self::CLOSED_STATUSES)
            ->orderBy('follow_up_at')
            ->limit(100)
            ->get()
            ->map(fn (QuoteRequest $q) => [
                'type'       => 'follow_up_due',
                'title'      => $q->company_name ?: $q->full_name,
                'subtitle'   => $q->follow_up_at->isPast()
                    ? "Follow-up overdue — {$q->ref_number}"
                    : "Follow-up due — {$q->ref_number}",
                'priority'   => $q->follow_up_at->lt($now->copy()->startOfDay()) ? 'urgent' : 'high',
                'due_at'     => $q->follow_up_at?->toIso8601String(),
                'action_url' => "/admin/quotes/{$q->id}",
                'status'     => $q->qualification_status ?? $q->status,
            ])->values();

        $proposalsAccepted = QuoteRequest::where('assigned_to', $userId)
            ->where('proposal_status', 'accepted')
            ->whereNull('order_id')
            ->orderByDesc('proposal_accepted_at')
            ->limit(100)
            ->get()
            ->map(fn (QuoteRequest $q) => [
                'type'       => 'proposal_accepted',
                'title'      => $q->company_name ?: $q->full_name,
                'subtitle'   => "Proposal {$q->proposal_number} accepted — convert to order",
                'priority'   => 'high',
                'due_at'     => $q->proposal_accepted_at?->toIso8601String(),
                'action_url' => "/admin/quotes/{$q->id}",
                'status'     => 'accepted',
            ])->values();

        // Finance snapshot records tagged to this person. try/catch because
        // this code can reach production before the snapshot migration runs,
        // and My Work must not 500 over a table that is not there yet.
        $financeTasks = collect();
        // Only someone who may open the board gets a link to it. For everyone
        // else the task is worked from My Work and the board link is absent
        // rather than offered and refused.
        $canOpenSnapshotBoard = AdminPermissions::can($user->role, 'finance.snapshot');
        try {
            $financeTasks = FinanceSnapshotItem::where('assigned_admin_id', $userId)
                ->whereNotIn('status', FinanceSnapshotItem::CLOSED_STATUSES)
                ->orderByRaw('date IS NULL, date')
                ->limit(100)
                ->get()
                ->map(fn (FinanceSnapshotItem $i) => [
                    'type'       => 'finance_task',
                    'id'         => $i->id,
                    'title'      => $i->ref . ($i->client ? " — {$i->client}" : ''),
                    'subtitle'   => ucwords(strtolower($i->category))
                        . ' · ' . number_format($i->amount, 2)
                        . ($i->comment ? " · {$i->comment}" : ''),
                    'priority'   => $i->date && $i->date->isPast() ? 'urgent' : ($i->status === 'Pending' ? 'high' : 'medium'),
                    'due_at'     => $i->date?->toIso8601String(),
                    // Structured, not only baked into the subtitle: the
                    // assignee cannot open the board (finance.snapshot),
                    // so My Work IS their whole view of the task — and the
                    // note field is editable, which needs the raw value.
                    'category'   => $i->category,
                    'client'     => $i->client,
                    'amount'     => (float) $i->amount,
                    'comment'    => $i->comment,
                    // Opening a tagged task lands on the task, never on the
                    // whole board. Most assignees are not finance and cannot
                    // open the board at all, so a link to it would 403 — and
                    // even for finance, the record they were tagged on is the
                    // thing they were asked about, not the six-category
                    // pipeline around it.
                    'action_url' => '/admin/my-work?finance_item=' . $i->id,
                    'status'     => $i->status,
                    // Tells the panel this row can be updated in place by its
                    // assignee via PATCH /admin/my-work/finance-items/{id}.
                    'editable'   => true,
                    // The select's options travel with the item, same contract
                    // as the EC lines and to-dos below — the panel renders
                    // whatever the API declares rather than holding its own
                    // copy of the list, which is how the two drift.
                    'status_options' => collect(FinanceSnapshotItem::STATUSES)
                        ->map(fn (string $s) => ['value' => $s, 'label' => $s])->values(),
                    // Present only for someone who may actually open the
                    // board. Null is the signal to render no such link.
                    'board_url'  => $canOpenSnapshotBoard
                        ? '/admin/finance-snapshot?item=' . $i->id
                        : null,
                ])->values();
        } catch (\Throwable) {
        }

        // Same shape and same guard as the finance tasks above: the EC
        // Invoice List can reach production before its migration.
        $ecInvoiceTasks = collect();
        try {
            $ecInvoiceTasks = EcInvoiceLine::with('group')
                ->where('assigned_admin_id', $userId)
                ->where('task_status', '!=', EcInvoiceLine::STATUS_COMPLETE)
                ->orderByRaw('invoice_date IS NULL, invoice_date')
                ->limit(100)
                ->get()
                ->map(function (EcInvoiceLine $l) {
                    $missing = array_values(array_filter([
                        $l->hasInvoiceFile() ? null : 'invoice PDF',
                        $l->hasProofFile() ? null : 'delivery proof',
                    ]));

                    return [
                        'type'       => 'ec_invoice_task',
                        'id'         => $l->id,
                        'title'      => $l->invoice_number
                            . ' — ' . trim(($l->group?->country_code ?? '') . ' ' . ($l->group?->customer_vat_id ?? '')),
                        'subtitle'   => 'ZM ' . ($l->group?->period ?? '?')
                            . ' · ' . number_format((float) $l->amount, 2)
                            . ($missing !== [] ? ' · missing: ' . implode(' and ', $missing) : ''),
                        'priority'   => $l->task_status === EcInvoiceLine::STATUS_REVIEW ? 'high' : 'medium',
                        'due_at'     => null,
                        // Deep link: the list opens the period and highlights
                        // the exact line, same contract as finance tasks.
                        'action_url' => '/admin/ec-invoices?period=' . ($l->group?->period ?? '') . '&line=' . $l->id,
                        'status'     => $l->task_status,
                        'editable'   => true,
                        // The EC statuses are not the finance-task statuses, so
                        // the select's options travel with the item.
                        'status_options' => collect(EcInvoiceLine::STATUS_LABELS)
                            ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                    ];
                })->values();
        } catch (\Throwable) {
        }

        // Same guard again: the to-do list can reach production before its
        // migration.
        $todoTasks = collect();
        try {
            $todoTasks = Todo::with('creator')
                ->where('assigned_admin_id', $userId)
                ->where('status', '!=', 'done')
                ->orderByRaw('due_on IS NULL, due_on')
                ->limit(100)
                ->get()
                ->map(fn (Todo $t) => [
                    'type'       => 'todo_task',
                    'id'         => $t->id,
                    'title'      => $t->title,
                    'subtitle'   => trim(($t->creator ? 'From ' . ($t->creator->display_name ?: $t->creator->name) : 'Team to-do')
                        . ($t->details ? ' · ' . \Illuminate\Support\Str::limit($t->details, 80) : '')),
                    'priority'   => $t->due_on?->isPast() ? 'urgent' : ($t->priority === 'high' ? 'high' : ($t->priority === 'low' ? 'low' : 'medium')),
                    'due_at'     => $t->due_on?->toIso8601String(),
                    'action_url' => '/admin/todos?todo=' . $t->id,
                    'status'     => $t->status,
                    'editable'   => true,
                    'status_options' => collect(Todo::STATUS_LABELS)
                        ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                ])->values();
        } catch (\Throwable) {
        }

        $pendingApprovals = collect();
        $accessRequests   = collect();

        if ($canManageCustomers) {
            $pendingApprovals = Customer::where('onboarding_status', 'pending_review')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (Customer $c) => [
                    'type'       => 'customer_approval_needed',
                    'title'      => $c->company_name ?: trim($c->first_name . ' ' . $c->last_name),
                    'subtitle'   => "New registration pending review — {$c->email}",
                    'priority'   => 'medium',
                    'due_at'     => null,
                    'action_url' => '/admin/customer-approvals',
                    'status'     => 'pending_review',
                ])->values();

            $accessRequests = CustomerAccessRequest::with('customer:id,first_name,last_name,company_name,email')
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (CustomerAccessRequest $r) => [
                    'type'       => 'customer_access_requested',
                    'title'      => $r->customer
                        ? ($r->customer->company_name ?: trim($r->customer->first_name . ' ' . $r->customer->last_name))
                        : 'Customer',
                    'subtitle'   => "Requested '{$r->requested_access}' access",
                    'priority'   => 'medium',
                    'due_at'     => $r->created_at?->toIso8601String(),
                    'action_url' => '/admin/customer-approvals?tab=access_requests',
                    'status'     => 'pending',
                ])->values();
        }

        return response()->json([
            'data' => [
                'assigned_leads'      => $assignedLeads,
                'due_follow_ups'      => $dueFollowUps,
                'proposals_accepted'  => $proposalsAccepted,
                'finance_tasks'       => $financeTasks,
                'ec_invoice_tasks'    => $ecInvoiceTasks,
                'todo_tasks'          => $todoTasks,
                'pending_approvals'   => $pendingApprovals->values(),
                'access_requests'     => $accessRequests->values(),
            ],
            'meta' => [
                'counts' => [
                    'assigned_leads'     => $assignedLeads->count(),
                    'due_follow_ups'     => $dueFollowUps->count(),
                    'proposals_accepted' => $proposalsAccepted->count(),
                    'finance_tasks'      => $financeTasks->count(),
                    'ec_invoice_tasks'   => $ecInvoiceTasks->count(),
                    'todo_tasks'         => $todoTasks->count(),
                    'pending_approvals'  => $pendingApprovals->count(),
                    'access_requests'    => $accessRequests->count(),
                ],
                'can_manage_customers' => $canManageCustomers,
            ],
            'message' => 'success',
        ]);
    }

    // ── PATCH /admin/my-work/finance-items/{id} ──────────────────────────────
    //
    // The assignee's half of the loop: the person a finance record was tagged
    // to updates its status and comment from My Work, without needing
    // finance.snapshot — being the assignee IS the authorization. This is the
    // only way into a snapshot record from outside finance, and it is
    // deliberate: closing the board to `admin` and the order manager must not
    // also stop finance from tagging them on a payment to chase. Whoever
    // created the record is notified of the change, so finance hears back
    // without chasing anyone.
    public function updateFinanceItem(Request $request, int $id): JsonResponse
    {
        $item = FinanceSnapshotItem::findOrFail($id);
        $user = $request->user();

        $mayEdit = $item->assigned_admin_id === $user->id
            || $user->hasPermission('finance.snapshot');

        if (! $mayEdit) {
            return response()->json([
                'message' => 'Only the person this task is assigned to (or finance) can update it.',
            ], 403);
        }

        $data = $request->validate([
            'status'  => ['required', Rule::in(FinanceSnapshotItem::STATUSES)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $previousStatus = $item->status;
        $item->update([
            'status'  => $data['status'],
            'comment' => array_key_exists('comment', $data) && $data['comment'] !== null
                ? $data['comment']
                : $item->comment,
        ]);

        if ($item->created_by && $item->created_by !== $user->id && $previousStatus !== $item->status) {
            AdminNotificationService::notifyUser(
                adminUserId: $item->created_by,
                type: 'finance_task_updated',
                title: "{$user->name} set {$item->ref} to {$item->status}",
                body: $item->comment,
                // The board, not My Work: this goes to whoever CREATED the
                // record, and only someone holding finance.snapshot can
                // create one. (A creator later moved off the finance role
                // would land on a 403 — an acceptable trade against a role
                // lookup on every status update.)
                actionUrl: '/admin/finance-snapshot?item=' . $item->id,
                severity: in_array($item->status, FinanceSnapshotItem::CLOSED_STATUSES, true) ? 'success' : 'info',
                relatedType: 'finance_snapshot_item',
                relatedId: $item->id,
                dedupeKey: "finance_task_updated:{$item->id}:{$item->status}",
            );
        }

        return response()->json([
            'data'    => ['id' => $item->id, 'status' => $item->status, 'comment' => $item->comment],
            'message' => "{$item->ref} updated.",
        ]);
    }

    // ── PATCH /admin/my-work/ec-invoice-lines/{id} ───────────────────────────
    //
    // Same contract as the finance-item update above: being the assignee IS
    // the authorization — the logistics person chasing a CMR does not hold
    // finance.manage — and whoever set the line up hears the status change
    // without chasing anyone.
    public function updateEcInvoiceLine(Request $request, int $id): JsonResponse
    {
        $line = EcInvoiceLine::with('group')->findOrFail($id);
        $user = $request->user();

        $mayEdit = $line->assigned_admin_id === $user->id
            || $user->hasPermission('finance.manage');

        if (! $mayEdit) {
            return response()->json([
                'message' => 'Only the person this line is assigned to (or finance) can update it.',
            ], 403);
        }

        $data = $request->validate([
            'task_status' => ['required', Rule::in(EcInvoiceLine::STATUSES)],
        ]);

        $previous = $line->task_status;
        $line->update(['task_status' => $data['task_status']]);

        if ($line->created_by && $line->created_by !== $user->id && $previous !== $line->task_status) {
            AdminNotificationService::notifyUser(
                adminUserId: $line->created_by,
                type: 'ec_invoice_task_updated',
                title: "{$user->name} set {$line->invoice_number} to "
                    . (EcInvoiceLine::STATUS_LABELS[$line->task_status] ?? $line->task_status),
                body: null,
                actionUrl: '/admin/ec-invoices?period=' . ($line->group?->period ?? '') . '&line=' . $line->id,
                severity: $line->task_status === EcInvoiceLine::STATUS_COMPLETE ? 'success' : 'info',
                relatedType: 'ec_invoice_line',
                relatedId: $line->id,
                dedupeKey: "ec_invoice_task_updated:{$line->id}:{$line->task_status}",
            );
        }

        return response()->json([
            'data'    => ['id' => $line->id, 'task_status' => $line->task_status],
            'message' => "{$line->invoice_number} updated.",
        ]);
    }

    private function leadPriority(QuoteRequest $q): string
    {
        if ($q->follow_up_at && $q->follow_up_at->isPast() && $q->follow_up_completed_at === null) {
            return 'urgent';
        }

        return match ($q->lead_priority) {
            'high'   => 'high',
            'low'    => 'low',
            default  => 'medium',
        };
    }
}
