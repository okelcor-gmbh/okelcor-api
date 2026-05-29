<?php

namespace App\Http\Controllers;

use App\Models\Order;
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
}
