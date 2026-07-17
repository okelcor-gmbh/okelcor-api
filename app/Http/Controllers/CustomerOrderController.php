<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerOrderController extends Controller
{
    public function __construct(private StripeService $stripeService) {}

    /**
     * POST /api/v1/auth/orders/{ref}/checkout
     *
     * Creates or refreshes a Stripe Checkout Session for a pending order
     * owned by the authenticated customer.
     */
    public function checkout(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        // Access guard — checkout requires explicit approval (CRM-4)
        if (! ($customer->approved_for_checkout ?? false)) {
            return response()->json([
                'message'               => 'Checkout access is pending approval. Please contact Okelcor.',
                'code'                  => 'checkout_not_approved',
                'approved_for_checkout' => false,
            ], 403);
        }

        $order = Order::where('ref', $ref)->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Ownership — match by email (orders table has no customer_id FK)
        if (strtolower($order->customer_email) !== strtolower($customer->email)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->payment_method !== 'stripe') {
            return response()->json([
                'message' => 'This order cannot be paid by Stripe.',
            ], 422);
        }

        if ($order->payment_status !== 'pending') {
            return response()->json([
                'message' => 'This order is not awaiting payment.',
            ], 409);
        }

        $order->load('items');

        try {
            $result = $this->stripeService->createCheckoutSessionForOrder($order);

            $order->update(['payment_session_id' => $result['checkout_session_id']]);

            Log::info('Customer checkout session created', [
                'order_ref'           => $ref,
                'customer_email'      => $customer->email,
                'checkout_session_id' => $result['checkout_session_id'],
            ]);

            return response()->json([
                'data' => [
                    'checkout_url'        => $result['checkout_url'],
                    'checkout_session_id' => $result['checkout_session_id'],
                    'order_ref'           => $ref,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Customer checkout session failed', [
                'order_ref' => $ref,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Payment gateway error. Please try again.',
            ], 502);
        }
    }

    /**
     * POST /api/v1/auth/orders/{ref}/reorder
     *
     * Re-prices a past order's line items against live product data — never
     * replays the old order's prices verbatim, and never creates a new
     * order itself. Returns a pre-fill payload the frontend drops into its
     * existing cart/checkout flow (the same one POST /orders already
     * expects), same as any other cart contents the customer built by hand.
     * Items whose product no longer exists, or is no longer active, are
     * returned separately so the customer can be told rather than silently
     * dropped or resold at a stale price.
     */
    public function reorder(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        $order = Order::where('ref', $ref)
            ->where('customer_email', $customer->email)
            ->with('items')
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $items       = [];
        $unavailable = [];

        foreach ($order->items as $orderItem) {
            $product = $orderItem->product_id
                ? Product::find($orderItem->product_id)
                : Product::where('sku', $orderItem->sku)->first();

            if (! $product || ! $product->is_active) {
                $unavailable[] = [
                    'sku'    => $orderItem->sku,
                    'name'   => $orderItem->name,
                    'reason' => $product ? 'no_longer_available' : 'no_longer_sold',
                ];
                continue;
            }

            $items[] = [
                'product_id'          => $product->id,
                'sku'                 => $product->sku,
                'name'                => $product->name,
                'brand'               => $product->brand,
                'size'                => $product->size,
                'quantity'            => $orderItem->quantity,
                'price'               => (float) $product->price,
                'price_b2b'           => $product->price_b2b !== null ? (float) $product->price_b2b : null,
                'price_b2c'           => $product->price_b2c !== null ? (float) $product->price_b2c : null,
                'original_unit_price' => (float) $orderItem->unit_price,
                'in_stock'            => (bool) $product->in_stock,
                'stock'               => (int) $product->stock,
            ];
        }

        return response()->json([
            'data' => [
                'order_ref'         => $order->ref,
                'items'             => $items,
                'unavailable_items' => $unavailable,
            ],
            'message' => 'success',
        ]);
    }
}
