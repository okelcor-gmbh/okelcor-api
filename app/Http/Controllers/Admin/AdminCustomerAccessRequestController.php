<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerAccessRequest;
use App\Services\CustomerTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM-8 — Admin review of customer-initiated access requests.
 *
 *   GET  /admin/customer-access-requests                 (customers.view)
 *   POST /admin/customer-access-requests/{id}/approve    (customers.manage)
 *   POST /admin/customer-access-requests/{id}/reject     (customers.manage)
 *
 * Approving grants the concrete CRM-4 access flag. 'higher_tier' is recorded as
 * approved but the actual tier change is left to the explicit set-tier action.
 */
class AdminCustomerAccessRequestController extends Controller
{
    private const ACCESS_FLAG = [
        'checkout'          => 'approved_for_checkout',
        'documents'         => 'approved_for_documents',
        'wholesale_pricing' => 'approved_for_wholesale_pricing',
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = CustomerAccessRequest::query()
            ->with('customer:id,first_name,last_name,email,company_name')
            ->orderByDesc('created_at');

        $query->where('status', $request->query('status', 'pending'));

        $paginated = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($r) => $this->format($r))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'pending_count' => CustomerAccessRequest::where('status', 'pending')->count(),
            ],
            'message' => 'success',
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $accessRequest = CustomerAccessRequest::with('customer')->findOrFail($id);

        if ($accessRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This access request has already been actioned.',
                'code'    => 'already_actioned',
                'status'  => $accessRequest->status,
            ], 422);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $accessRequest->update([
            'status'       => 'approved',
            'reviewed_by'  => $request->user()?->id,
            'reviewed_at'  => now(),
            'review_notes' => $data['notes'] ?? null,
        ]);

        // Grant the concrete access flag where one maps directly.
        $customer = $accessRequest->customer;
        if ($customer && isset(self::ACCESS_FLAG[$accessRequest->requested_access])) {
            $customer->update([self::ACCESS_FLAG[$accessRequest->requested_access] => true]);
        }

        if ($customer) {
            CustomerTimelineService::record(
                $customer->id,
                'access_request_approved',
                'Access request approved',
                "Approved request for '{$accessRequest->requested_access}'."
                    . ($data['notes'] ?? ''),
                ['requested_access' => $accessRequest->requested_access, 'request_id' => $accessRequest->id],
                $request->user()?->id
            );
        }

        return response()->json([
            'success' => true,
            'data'    => $this->format($accessRequest->fresh()->load('customer:id,first_name,last_name,email,company_name')),
            'message' => 'Access request approved.',
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $accessRequest = CustomerAccessRequest::with('customer')->findOrFail($id);

        if ($accessRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This access request has already been actioned.',
                'code'    => 'already_actioned',
                'status'  => $accessRequest->status,
            ], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $accessRequest->update([
            'status'       => 'rejected',
            'reviewed_by'  => $request->user()?->id,
            'reviewed_at'  => now(),
            'review_notes' => $data['reason'] ?? null,
        ]);

        if ($accessRequest->customer) {
            CustomerTimelineService::record(
                $accessRequest->customer->id,
                'access_request_rejected',
                'Access request rejected',
                "Rejected request for '{$accessRequest->requested_access}'."
                    . ($data['reason'] ? ' Reason: ' . $data['reason'] : ''),
                ['requested_access' => $accessRequest->requested_access, 'request_id' => $accessRequest->id],
                $request->user()?->id
            );
        }

        return response()->json([
            'success' => true,
            'data'    => $this->format($accessRequest->fresh()->load('customer:id,first_name,last_name,email,company_name')),
            'message' => 'Access request rejected.',
        ]);
    }

    private function format(CustomerAccessRequest $r): array
    {
        return [
            'id'               => $r->id,
            'customer_id'      => $r->customer_id,
            'customer'         => $r->relationLoaded('customer') && $r->customer ? [
                'id'           => $r->customer->id,
                'full_name'    => trim($r->customer->first_name . ' ' . $r->customer->last_name),
                'email'        => $r->customer->email,
                'company_name' => $r->customer->company_name,
            ] : null,
            'requested_access' => $r->requested_access,
            'status'           => $r->status,
            'reason'           => $r->reason,
            'reviewed_by'      => $r->reviewed_by,
            'reviewed_at'      => $r->reviewed_at?->toIso8601String(),
            'review_notes'     => $r->review_notes,
            'created_at'       => $r->created_at?->toIso8601String(),
        ];
    }
}
