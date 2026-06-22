<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccessRequest;
use App\Services\AdminNotificationService;
use App\Services\CustomerTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM-8 — Customer-initiated access requests (portal).
 *
 *   GET  /api/v1/auth/customer/access-requests   — list own requests
 *   POST /api/v1/auth/customer/access-requests   — request access
 *
 * Internal risk/health/approval data is never exposed here.
 */
class CustomerAccessRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $requests = CustomerAccessRequest::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => $this->format($r));

        return response()->json([
            'data'    => $requests->values(),
            'message' => 'success',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $request->user();

        $data = $request->validate([
            'requested_access' => ['required', Rule::in(['checkout', 'documents', 'wholesale_pricing', 'higher_tier'])],
            'reason'           => ['nullable', 'string', 'max:2000'],
        ]);

        // Don't allow stacking duplicate pending requests for the same access.
        $existing = CustomerAccessRequest::where('customer_id', $customer->id)
            ->where('requested_access', $data['requested_access'])
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already have a pending request for this access. Okelcor is reviewing it.',
                'code'    => 'request_already_pending',
                'data'    => $this->format($existing),
            ], 409);
        }

        $accessRequest = CustomerAccessRequest::create([
            'customer_id'      => $customer->id,
            'requested_access' => $data['requested_access'],
            'status'           => 'pending',
            'reason'           => $data['reason'] ?? null,
        ]);

        CustomerTimelineService::record(
            $customer->id,
            'access_requested',
            'Customer requested access',
            "Customer requested '{$data['requested_access']}' access."
                . ($data['reason'] ? ' Reason: ' . $data['reason'] : ''),
            ['requested_access' => $data['requested_access'], 'request_id' => $accessRequest->id]
        );

        // CRM-3B — alert admins who can action access requests.
        $customerName = $customer->company_name ?: trim($customer->first_name . ' ' . $customer->last_name);
        AdminNotificationService::notifyPermission(
            permission:  'customers.manage',
            type:        'customer_access_requested',
            title:       'Customer access request',
            body:        sprintf("%s requested '%s' access.", $customerName, $data['requested_access']),
            actionUrl:   '/admin/customer-approvals?tab=access_requests',
            severity:    'warning',
            relatedType: 'customer',
            relatedId:   $customer->id,
            metadata:    ['requested_access' => $data['requested_access'], 'request_id' => $accessRequest->id],
            dedupeKey:   "customer_access_requested:access_request:{$accessRequest->id}",
        );

        return response()->json([
            'data'    => $this->format($accessRequest),
            'message' => 'Your access request has been submitted. Okelcor will review it shortly.',
        ], 201);
    }

    private function format(CustomerAccessRequest $r): array
    {
        return [
            'id'               => $r->id,
            'requested_access' => $r->requested_access,
            'status'           => $r->status,
            'reason'           => $r->reason,
            'created_at'       => $r->created_at?->toIso8601String(),
            'reviewed_at'      => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
