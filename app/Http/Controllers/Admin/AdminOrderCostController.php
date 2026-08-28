<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The invoiceless costs on an order — eBay fees, Stripe fees, bank charges.
 *
 * Deliberately dumb rows: finance reads the fee off a statement and types it
 * against the order, and profitability subtracts the sum. Nothing here is
 * derived from gateway APIs — a fee a person can see and vouch for beats a
 * fee a formula guessed.
 */
class AdminOrderCostController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/orders/{id}/costs — finance.view
    // -------------------------------------------------------------------------
    public function index(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $costs = $order->costs()->with('recordedBy:id,name')->get();

        return response()->json([
            'data' => [
                'order_ref' => $order->ref,
                'costs'     => $costs->map(fn (OrderCost $c) => $this->format($c))->values(),
                'total'     => round((float) $costs->sum('amount'), 2),
                'by_type'   => $costs->groupBy('type')
                    ->map(fn ($group, $type) => [
                        'type'  => $type,
                        'label' => OrderCost::TYPES[$type] ?? $type,
                        'total' => round((float) $group->sum('amount'), 2),
                    ])->values(),
            ],
            'meta'    => ['types' => OrderCost::TYPES],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/orders/{id}/costs — finance.manage
    // -------------------------------------------------------------------------
    public function store(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'type'     => ['required', Rule::in(array_keys(OrderCost::TYPES))],
            'label'    => ['nullable', 'string', 'max:150'],
            // Negative allowed — a refunded fee is a real event; the normal
            // entry is a positive magnitude ("this cost €12.40").
            'amount'   => ['required', 'numeric', 'min:-99999999', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $cost = OrderCost::create([
            'order_id'    => $order->id,
            'order_ref'   => $order->ref,
            'type'        => $data['type'],
            'label'       => $data['label'] ?? null,
            'amount'      => $data['amount'],
            'currency'    => strtoupper($data['currency'] ?? ($order->currency ?: 'EUR')),
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json([
            'data'    => $this->format($cost->fresh('recordedBy')),
            'message' => (OrderCost::TYPES[$data['type']]) . " recorded against order {$order->ref}.",
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/order-costs/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $cost = OrderCost::findOrFail($id);

        $data = $request->validate([
            'type'     => ['sometimes', Rule::in(array_keys(OrderCost::TYPES))],
            'label'    => ['sometimes', 'nullable', 'string', 'max:150'],
            'amount'   => ['sometimes', 'numeric', 'min:-99999999', 'max:99999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $cost->update($data);

        return response()->json([
            'data'    => $this->format($cost->fresh('recordedBy')),
            'message' => 'Cost line updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/order-costs/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function destroy(int $id): JsonResponse
    {
        $cost = OrderCost::findOrFail($id);
        $cost->delete();

        return response()->json(['message' => 'Cost line removed.']);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function format(OrderCost $c): array
    {
        return [
            'id'          => $c->id,
            'order_ref'   => $c->order_ref,
            'type'        => $c->type,
            'type_label'  => OrderCost::TYPES[$c->type] ?? $c->type,
            'label'       => $c->label,
            'amount'      => (float) $c->amount,
            'currency'    => $c->currency,
            'recorded_by' => $c->recordedBy?->name,
            'recorded_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
