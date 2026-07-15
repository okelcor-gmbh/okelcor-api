<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Corrects line items on an order created directly in this system — wrong
 * price, wrong quantity, wrong product name. Deliberately does NOT apply to
 * eBay-sourced orders (Order::source === 'ebay'): those are authoritative
 * from eBay and get overwritten on the next sync, so a local edit here would
 * just be silently reverted and give a false sense of having fixed anything.
 *
 * Respects the same financial-lock rule as AdminOrderFinancialsController:
 * once a commercial document has been issued, item changes go through the
 * revision-request/approve workflow instead of applying immediately.
 */
class AdminOrderItemController extends Controller
{
    // ── PATCH /admin/orders/{orderId}/items/{itemId} ─────────────────────────

    public function update(Request $request, int $orderId, int $itemId): JsonResponse
    {
        $order = Order::findOrFail($orderId);

        if ($blocked = $this->assertEditable($order)) {
            return $blocked;
        }

        $item = OrderItem::where('order_id', $order->id)->findOrFail($itemId);

        $data = $request->validate([
            'sku'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'name'       => ['sometimes', 'string', 'max:255'],
            'brand'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'size'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit_price' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'quantity'   => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'reason'     => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $oldSnapshot  = $item->only(['sku', 'name', 'brand', 'size', 'unit_price', 'quantity', 'line_total']);
        $oldLineTotal = (float) $item->line_total;

        $item->fill(collect($data)->except('reason')->all());
        $item->line_total = round((float) $item->unit_price * (int) $item->quantity, 2);
        $item->save();

        $this->applyOrderDelta(
            $request, $order, $oldLineTotal, (float) $item->line_total, $data['reason'],
            'item_corrected', $oldSnapshot, $item->fresh()->only(['sku', 'name', 'brand', 'size', 'unit_price', 'quantity', 'line_total'])
        );

        return response()->json([
            'data'    => $this->formatItem($item->fresh()),
            'message' => 'Item updated successfully.',
        ]);
    }

    // ── POST /admin/orders/{orderId}/items ────────────────────────────────────

    public function store(Request $request, int $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);

        if ($blocked = $this->assertEditable($order)) {
            return $blocked;
        }

        $data = $request->validate([
            'sku'        => ['nullable', 'string', 'max:100'],
            'name'       => ['required', 'string', 'max:255'],
            'brand'      => ['nullable', 'string', 'max:100'],
            'size'       => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'quantity'   => ['required', 'integer', 'min:1', 'max:100000'],
            'reason'     => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $lineTotal = round($data['unit_price'] * $data['quantity'], 2);

        $item = OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => null,
            'sku'        => $data['sku'] ?? null,
            'brand'      => $data['brand'] ?? '',
            'name'       => $data['name'],
            'size'       => $data['size'] ?? '',
            'unit_price' => $data['unit_price'],
            'quantity'   => $data['quantity'],
            'line_total' => $lineTotal,
        ]);

        $this->applyOrderDelta(
            $request, $order, 0, $lineTotal, $data['reason'],
            'item_added', null, $item->only(['sku', 'name', 'brand', 'size', 'unit_price', 'quantity', 'line_total'])
        );

        return response()->json([
            'data'    => $this->formatItem($item),
            'message' => 'Item added successfully.',
        ], 201);
    }

    // ── DELETE /admin/orders/{orderId}/items/{itemId} ─────────────────────────

    public function destroy(Request $request, int $orderId, int $itemId): JsonResponse
    {
        $order = Order::findOrFail($orderId);

        if ($blocked = $this->assertEditable($order)) {
            return $blocked;
        }

        $item = OrderItem::where('order_id', $order->id)->findOrFail($itemId);

        if (OrderItem::where('order_id', $order->id)->count() <= 1) {
            return response()->json([
                'message' => 'Cannot delete the only item on an order — edit it instead, or cancel the order.',
                'code'    => 'cannot_delete_last_item',
            ], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $oldSnapshot  = $item->only(['sku', 'name', 'brand', 'size', 'unit_price', 'quantity', 'line_total']);
        $oldLineTotal = (float) $item->line_total;
        $item->delete();

        $this->applyOrderDelta($request, $order, $oldLineTotal, 0, $data['reason'], 'item_removed', $oldSnapshot, null);

        return response()->json(['message' => 'Item removed successfully.']);
    }

    // -------------------------------------------------------------------------

    private function assertEditable(Order $order): ?JsonResponse
    {
        if ($order->source === 'ebay') {
            return response()->json([
                'message' => 'This order is synced from eBay — item quantities and prices are managed there and will be overwritten on the next sync. Correct the listing/order in eBay instead.',
                'code'    => 'ebay_order_not_editable',
            ], 403);
        }

        if ($order->isFinancialsLocked()) {
            return response()->json([
                'message'            => 'Order financials are locked because a commercial document has been issued. Use the revision request workflow.',
                'code'               => 'financials_locked',
                'requires_supersede' => true,
            ], 423);
        }

        return null;
    }

    /**
     * Applies just the delta this one item change caused to subtotal/total —
     * the same surgical swap-one-component approach AdminOrderFinancialsController
     * already uses for delivery_fee, so any tax/discount baked into `total`
     * from elsewhere is left untouched rather than assumed away.
     */
    private function applyOrderDelta(
        Request $request, Order $order, float $oldLineTotal, float $newLineTotal,
        string $reason, string $action, ?array $oldItem, ?array $newItem
    ): void {
        $delta       = round($newLineTotal - $oldLineTotal, 2);
        $newSubtotal = round((float) $order->subtotal + $delta, 2);
        $newTotal    = round((float) $order->total + $delta, 2);
        $oldTotal    = (float) $order->total;

        $order->update(['subtotal' => $newSubtotal, 'total' => $newTotal]);

        // old_value/new_value are VARCHAR(100) — keep them short, bounded
        // summaries; the full item snapshot goes in `notes` (unbounded TEXT).
        $this->writeLog($request, $order, $action, [
            'old_value' => $oldItem ? $this->summarizeItem($oldItem) : null,
            'new_value' => $newItem ? $this->summarizeItem($newItem) : null,
            'notes'     => $reason . " (order total: {$oldTotal} \u{2192} {$newTotal}) — "
                . ($oldItem ? 'before: ' . json_encode($oldItem) . ' ' : '')
                . ($newItem ? 'after: ' . json_encode($newItem) : ''),
        ]);
    }

    private function summarizeItem(array $item): string
    {
        return substr(
            trim(($item['name'] ?? '') . ' — qty ' . ($item['quantity'] ?? '?') . ' @ ' . ($item['unit_price'] ?? '?')),
            0, 100
        );
    }

    private function formatItem(OrderItem $i): array
    {
        return [
            'id'           => $i->id,
            'product_id'   => $i->product_id,
            'sku'          => $i->sku,
            'brand'        => $i->brand,
            'name'         => $i->name,
            'size'         => $i->size,
            'unit_price'   => (float) $i->unit_price,
            'quantity'     => (int) $i->quantity,
            'line_total'   => (float) $i->line_total,
        ];
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
            Log::warning('OrderLog write failed (item edit)', [
                'order_ref' => $order->ref,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
