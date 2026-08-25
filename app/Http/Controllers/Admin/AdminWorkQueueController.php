<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerAccessRequest;
use App\Models\Customer;
use App\Models\FinanceSnapshotItem;
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
                    'action_url' => '/admin/finance-snapshot',
                    'status'     => $i->status,
                    // Tells the panel this row can be updated in place by its
                    // assignee via PATCH /admin/my-work/finance-items/{id}.
                    'editable'   => true,
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
                'pending_approvals'   => $pendingApprovals->values(),
                'access_requests'     => $accessRequests->values(),
            ],
            'meta' => [
                'counts' => [
                    'assigned_leads'     => $assignedLeads->count(),
                    'due_follow_ups'     => $dueFollowUps->count(),
                    'proposals_accepted' => $proposalsAccepted->count(),
                    'finance_tasks'      => $financeTasks->count(),
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
    // finance.manage — being the assignee IS the authorization. Whoever
    // created the record is notified of the change, so finance hears back
    // without chasing anyone.
    public function updateFinanceItem(Request $request, int $id): JsonResponse
    {
        $item = FinanceSnapshotItem::findOrFail($id);
        $user = $request->user();

        $mayEdit = $item->assigned_admin_id === $user->id
            || $user->hasPermission('finance.manage');

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
                actionUrl: '/admin/finance-snapshot',
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
