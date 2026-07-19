<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use App\Services\AdminNotificationService;
use App\Services\TradeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'reason'                        => ['required', 'string', 'min:5', 'max:1000'],
            'changes'                       => ['required', 'array', 'min:1'],
            'changes.delivery_fee'          => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            // Item-level corrections — same fields as the direct-edit
            // endpoints (AdminOrderItemController), just deferred to approval
            // because this order's financials are locked.
            'changes.items'                 => ['sometimes', 'array'],
            'changes.items.*.id'            => ['required', 'integer'],
            'changes.items.*.sku'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.items.*.name'          => ['sometimes', 'string', 'max:255'],
            'changes.items.*.brand'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.items.*.size'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'changes.items.*.unit_price'    => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'changes.items.*.quantity'      => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'changes.new_items'             => ['sometimes', 'array'],
            'changes.new_items.*.name'       => ['required', 'string', 'max:255'],
            'changes.new_items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'changes.new_items.*.quantity'   => ['required', 'integer', 'min:1', 'max:100000'],
            'changes.new_items.*.sku'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.new_items.*.brand'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.new_items.*.size'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'changes.remove_item_ids'       => ['sometimes', 'array'],
            'changes.remove_item_ids.*'     => ['integer'],
        ]);

        $hasItemChanges = $request->filled('changes.items')
            || $request->filled('changes.new_items')
            || $request->filled('changes.remove_item_ids');

        if ($hasItemChanges && $order->source === 'ebay') {
            return response()->json([
                'message' => 'This order is synced from eBay — item quantities and prices are managed there and will be overwritten on the next sync. Correct the listing/order in eBay instead.',
                'code'    => 'ebay_order_not_editable',
            ], 403);
        }

        $order->update([
            'financials_revision_required'     => true,
            'financials_revision_reason'       => $request->input('reason'),
            'financials_revision_requested_by' => $admin?->id,
            'financials_revision_requested_at' => now(),
            'financials_revision_changes'      => $request->input('changes'),
        ]);

        // new_value is VARCHAR(100) — a short summary, not the full payload
        // (which is already persisted in financials_revision_changes above
        // and quoted in full in `notes`, an unbounded TEXT column).
        $this->writeLog($request, $order, 'financial_revision_requested', [
            'new_value' => substr('changes: ' . implode(',', array_keys($request->input('changes'))), 0, 100),
            'notes'     => 'Revision requested: ' . $request->input('reason') . ' — ' . json_encode($request->input('changes')),
        ]);

        // Previously silent — a revision request generated a log entry but
        // no notification of any kind, so nothing prompted anyone to
        // actually approve it. Fanned out (not just to whoever's online)
        // since this blocks re-issuing a document until resolved.
        AdminNotificationService::notifyPermission(
            permission:  'orders.approve_financial_revision',
            type:        'financial_revision_requested',
            title:       'Financial revision requested',
            body:        "Order {$order->ref}: " . $request->input('reason'),
            actionUrl:   "/admin/orders/{$order->id}",
            severity:    'warning',
            relatedType: 'order',
            relatedId:   $order->id,
            dedupeKey:   "financial_revision_requested:order:{$order->id}",
        );

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

        $changes     = $order->financials_revision_changes ?? [];
        $requestedBy = $order->financials_revision_requested_by;

        try {
            [$affected, $appliedChanges] = DB::transaction(function () use ($order, $admin, $changes) {
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

                // Existing item corrections — same surgical delta approach as
                // the direct-edit path (AdminOrderItemController) for
                // unlocked orders.
                foreach ($changes['items'] ?? [] as $itemChange) {
                    $item = OrderItem::where('order_id', $order->id)->find($itemChange['id'] ?? null);
                    if (! $item) {
                        continue;
                    }

                    $oldLineTotal = (float) $item->line_total;
                    $item->fill(collect($itemChange)->except('id')->all());
                    $item->line_total = round((float) $item->unit_price * (int) $item->quantity, 2);
                    $item->save();

                    $delta            = round((float) $item->line_total - $oldLineTotal, 2);
                    $order->subtotal  = round((float) $order->subtotal + $delta, 2);
                    $order->total     = round((float) $order->total + $delta, 2);
                    $appliedChanges['items'][] = ['id' => $item->id, 'line_total_delta' => $delta];
                }

                // New items added as part of the revision.
                foreach ($changes['new_items'] ?? [] as $newItemData) {
                    $lineTotal = round((float) $newItemData['unit_price'] * (int) $newItemData['quantity'], 2);
                    $newItem   = OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => null,
                        'sku'        => $newItemData['sku'] ?? null,
                        'brand'      => $newItemData['brand'] ?? '',
                        'name'       => $newItemData['name'],
                        'size'       => $newItemData['size'] ?? '',
                        'unit_price' => $newItemData['unit_price'],
                        'quantity'   => $newItemData['quantity'],
                        'line_total' => $lineTotal,
                    ]);

                    $order->subtotal = round((float) $order->subtotal + $lineTotal, 2);
                    $order->total    = round((float) $order->total + $lineTotal, 2);
                    $appliedChanges['new_items'][] = ['id' => $newItem->id, 'line_total' => $lineTotal];
                }

                // Items removed as part of the revision — guarded so an
                // order can never end up with zero items via an approved
                // revision. Throwing rolls back everything else in this
                // transaction too (superseded docs, item edits/adds above).
                $removeIds = array_values(array_filter($changes['remove_item_ids'] ?? []));
                if ($removeIds) {
                    $remaining = OrderItem::where('order_id', $order->id)->whereNotIn('id', $removeIds)->count();
                    if ($remaining < 1) {
                        throw new \RuntimeException('revision_would_empty_order');
                    }

                    foreach (OrderItem::where('order_id', $order->id)->whereIn('id', $removeIds)->get() as $item) {
                        $lineTotal = (float) $item->line_total;
                        $item->delete();
                        $order->subtotal = round((float) $order->subtotal - $lineTotal, 2);
                        $order->total    = round((float) $order->total - $lineTotal, 2);
                        $appliedChanges['removed_items'][] = ['id' => $item->id, 'line_total' => $lineTotal];
                    }
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

                return [$affected, $appliedChanges];
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'revision_would_empty_order') {
                return response()->json([
                    'message' => 'This revision would remove every item on the order — add a replacement item to the revision first.',
                    'code'    => 'revision_would_empty_order',
                ], 422);
            }
            throw $e;
        }

        $this->writeLog($request, $order, 'financial_revision_approved', [
            'notes'     => 'Revision approved. ' . count($affected) . ' document(s) superseded. Please regenerate. Changes: ' . json_encode($appliedChanges),
            'new_value' => substr('changes: ' . implode(',', array_keys($appliedChanges)), 0, 100),
        ]);

        if ($requestedBy) {
            AdminNotificationService::notifyUser(
                adminUserId: $requestedBy,
                type:        'financial_revision_approved',
                title:       'Your revision request was approved',
                body:        "Order {$order->ref} — {$affected->count()} document(s) superseded and will need to be regenerated.",
                actionUrl:   "/admin/orders/{$order->id}",
                severity:    'success',
                relatedType: 'order',
                relatedId:   $order->id,
            );
        }

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
        $requestedBy    = $order->financials_revision_requested_by;

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

        if ($requestedBy) {
            AdminNotificationService::notifyUser(
                adminUserId: $requestedBy,
                type:        'financial_revision_rejected',
                title:       'Your revision request was rejected',
                body:        "Order {$order->ref}" . ($request->input('reason') ? ' — ' . $request->input('reason') : ''),
                actionUrl:   "/admin/orders/{$order->id}",
                severity:    'warning',
                relatedType: 'order',
                relatedId:   $order->id,
            );
        }

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
