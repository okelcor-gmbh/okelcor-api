<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerAccessRequest;
use App\Models\Customer;
use App\Models\QuoteRequest;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'pending_approvals'   => $pendingApprovals->values(),
                'access_requests'     => $accessRequests->values(),
            ],
            'meta' => [
                'counts' => [
                    'assigned_leads'     => $assignedLeads->count(),
                    'due_follow_ups'     => $dueFollowUps->count(),
                    'proposals_accepted' => $proposalsAccepted->count(),
                    'pending_approvals'  => $pendingApprovals->count(),
                    'access_requests'    => $accessRequests->count(),
                ],
                'can_manage_customers' => $canManageCustomers,
            ],
            'message' => 'success',
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
