<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use App\Services\TradeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminOrderFinancialsController extends Controller
{
    /**
     * POST /api/v1/admin/orders/{id}/financials/revision-request
     *
     * Submit a revision request for locked order financials.
     * Permission: orders.update
     *
     * Does NOT apply changes — stores them for approval only.
     */
    public function requestRevision(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $admin = $request->user();

        if (! $order->isFinancialsLocked()) {
            return response()->json([
                'message' => 'Order financials are not locked. Use PATCH /financials to update directly.',
            ], 422);
        }

        if ($order->financials_revision_required) {
            return response()->json([
                'message' => 'A financial revision is already pending approval for this order.',
                'code'    => 'revision_already_pending',
            ], 409);
        }

        $request->validate([
            'reason'               => ['required', 'string', 'min:5', 'max:1000'],
            'changes'              => ['required', 'array', 'min:1'],
            'changes.delivery_fee' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $order->update([
            'financials_revision_required'     => true,
            'financials_revision_reason'       => $request->input('reason'),
            'financials_revision_requested_by' => $admin?->id,
            'financials_revision_requested_at' => now(),
            'financials_revision_changes'      => $request->input('changes'),
        ]);

        $this->writeLog($request, $order, 'financial_revision_requested', [
            'notes'     => 'Revision requested: ' . $request->input('reason'),
            'new_value' => json_encode($request->input('changes')),
        ]);

        return response()->json([
            'data' => [
                'financials_revision_required' => true,
                'proposed_changes'             => $request->input('changes'),
            ],
            'message' => 'Financial revision request submitted. Awaiting approval from an admin.',
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/financials/approve-revision
     *
     * Approve a pending financial revision.
     * Permission: orders.approve_financial_revision (super_admin, admin only)
     *
     * Automatically supersedes: order_confirmation, proforma, commercial_invoice.
     * Applies the stored changes and relocks financials.
     */
    public function approveRevision(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $admin = $request->user();

        if (! $order->financials_revision_required) {
            return response()->json([
                'message' => 'No pending financial revision for this order.',
            ], 422);
        }

        $changes = $order->financials_revision_changes ?? [];

        // Supersede affected issued/sent documents
        $supersedable = ['order_confirmation', 'proforma', 'commercial_invoice'];
        $affected = TradeDocument::where('order_id', $order->id)
            ->whereIn('status', ['issued', 'sent'])
            ->whereIn('type', $supersedable)
            ->get();

        foreach ($affected as $doc) {
            $doc->update([
                'status'           => 'superseded',
                'superseded_at'    => now(),
                'superseded_by_id' => $admin?->id,
                'supersede_reason' => 'Financial revision approved — ' . $order->financials_revision_reason,
            ]);
        }

        // Apply the approved changes
        $appliedChanges = [];

        if (isset($changes['delivery_fee'])) {
            $oldDeliveryCost      = (float) $order->delivery_cost;
            $oldTotal             = (float) $order->total;
            $newDeliveryCost      = (float) $changes['delivery_fee'];
            $newTotal             = round($oldTotal - $oldDeliveryCost + $newDeliveryCost, 2);
            $order->delivery_cost = $newDeliveryCost;
            $order->total         = $newTotal;
            $appliedChanges['delivery_cost'] = ['from' => $oldDeliveryCost, 'to' => $newDeliveryCost];
            $appliedChanges['total']         = ['from' => $oldTotal,        'to' => $newTotal];
        }

        // Clear revision flags, relock with updated reason
        $order->financials_revision_required     = false;
        $order->financials_revision_reason       = null;
        $order->financials_revision_requested_by = null;
        $order->financials_revision_requested_at = null;
        $order->financials_revision_changes      = null;
        $order->financials_locked_at             = now();
        $order->financials_locked_by             = $admin?->id;
        $order->financials_lock_reason           = 'Revised financials approved';
        $order->save();

        $this->writeLog($request, $order, 'financial_revision_approved', [
            'notes'     => 'Revision approved. ' . count($affected) . ' document(s) superseded. Please regenerate.',
            'new_value' => json_encode($appliedChanges),
        ]);

        return response()->json([
            'data' => [
                'superseded_documents' => $affected->pluck('number')->filter()->values(),
                'changes_applied'      => $appliedChanges,
            ],
            'message' => 'Financial revision approved. ' . count($affected) . ' document(s) superseded. Please regenerate the affected documents.',
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/financials/reject-revision
     *
     * Reject a pending financial revision without applying any changes.
     * Permission: orders.approve_financial_revision
     */
    public function rejectRevision(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! $order->financials_revision_required) {
            return response()->json([
                'message' => 'No pending financial revision for this order.',
            ], 422);
        }

        $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $originalReason = $order->financials_revision_reason;

        $order->update([
            'financials_revision_required'     => false,
            'financials_revision_reason'       => null,
            'financials_revision_requested_by' => null,
            'financials_revision_requested_at' => null,
            'financials_revision_changes'      => null,
        ]);

        $this->writeLog($request, $order, 'financial_revision_rejected', [
            'old_value' => $originalReason,
            'notes'     => 'Revision rejected: ' . ($request->input('reason') ?? 'No reason provided'),
        ]);

        return response()->json([
            'message' => 'Financial revision request rejected. Order financials remain locked.',
        ]);
    }

    private function writeLog(Request $request, Order $order, string $action, array $extra = []): void
    {
        try {
            $admin = $request->user();
            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => $action,
                'old_value'        => $extra['old_value'] ?? null,
                'new_value'        => $extra['new_value'] ?? null,
                'notes'            => $extra['notes'] ?? null,
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed', [
                'order_ref' => $order->ref,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
