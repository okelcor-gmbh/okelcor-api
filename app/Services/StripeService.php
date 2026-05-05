<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Stripe\StripeClient;

class StripeService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        $this->stripe = new StripeClient($secret);
    }

    public function createCheckoutSession(array $orderData): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');

        $orderRef = $orderData['ref'] ?? $orderData['order_ref'] ?? null;

        $successUrl = $frontendUrl . '/checkout/return?session_id={CHECKOUT_SESSION_ID}';
        if ($orderRef) {
            $successUrl .= '&order_ref=' . urlencode((string) $orderRef);
        }

        $payload = [
            'mode'        => 'payment',
            'line_items'  => $this->lineItems($orderData),
            'success_url' => $successUrl,
            'cancel_url'  => $frontendUrl . '/checkout/cancel',
        ];

        $email = $orderData['customer_email'] ?? $orderData['email'] ?? null;
        if (is_string($email) && $email !== '') {
            $payload['customer_email'] = $email;
        }

        if (is_string($orderRef) && $orderRef !== '') {
            $payload['client_reference_id'] = $orderRef;
            $payload['metadata'] = ['order_ref' => $orderRef];
            $payload['payment_intent_data'] = [
                'metadata' => ['order_ref' => $orderRef],
            ];
        }

        // Apply discount coupon when a promo code reduced the order total.
        // Stripe does not accept negative line items, so we create a one-time
        // coupon with amount_off and attach it via discounts[].
        $discountAmount = (float) ($orderData['discount_amount'] ?? 0);
        if ($discountAmount > 0) {
            $currency = strtolower((string) ($orderData['currency'] ?? config('services.stripe.currency', 'eur')));
            $coupon   = $this->stripe->coupons->create([
                'amount_off' => (int) round($discountAmount * 100),
                'currency'   => $currency,
                'duration'   => 'once',
                'name'       => $orderData['discount_label'] ?? 'Discount',
            ]);
            $payload['discounts'] = [['coupon' => $coupon->id]];
        }

        Log::info('Stripe checkout success_url', [
            'success_url' => $successUrl,
            'order_ref'   => $orderRef,
        ]);

        $session = $this->stripe->checkout->sessions->create($payload);

        return [
            'checkout_session_id' => $session->id,
            'checkout_url'        => $session->url,
        ];
    }

    public function createCheckoutSessionForOrder(Order $order): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');
        $orderRef    = $order->ref;
        $currency    = strtolower((string) config('services.stripe.currency', 'eur'));

        $lineItems = [];

        foreach ($order->items as $item) {
            $name = trim($item->brand . ' ' . $item->name);
            if ($item->size) {
                $name .= ' — ' . $item->size;
            }

            $lineItems[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'product_data' => ['name' => $name],
                    'unit_amount'  => (int) round((float) $item->unit_price * 100),
                ],
                'quantity' => max(1, (int) $item->quantity),
            ];
        }

        if ((float) $order->delivery_cost > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'product_data' => ['name' => 'Delivery'],
                    'unit_amount'  => (int) round((float) $order->delivery_cost * 100),
                ],
                'quantity' => 1,
            ];
        }

        // Add VAT as a separate line item so Stripe collects the correct gross total.
        // Only applies when tax_amount > 0 (standard rate). Reverse charge and exempt
        // orders have tax_amount = 0 so no line item is added.
        if ((float) $order->tax_amount > 0) {
            $rateLabel   = number_format((float) $order->tax_rate, 0);
            $lineItems[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'product_data' => ['name' => "VAT ({$rateLabel}%)"],
                    'unit_amount'  => (int) round((float) $order->tax_amount * 100),
                ],
                'quantity' => 1,
            ];
        }

        if ($lineItems === []) {
            throw new InvalidArgumentException('Stripe checkout requires at least one line item.');
        }

        $successUrl = $frontendUrl . '/checkout/return?session_id={CHECKOUT_SESSION_ID}&order_ref=' . urlencode($orderRef);
        $cancelUrl  = $frontendUrl . '/account/orders/' . urlencode($orderRef);

        $payload = [
            'mode'                => 'payment',
            'line_items'          => $lineItems,
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
            'customer_email'      => $order->customer_email,
            'client_reference_id' => $orderRef,
            'metadata'            => ['order_ref' => $orderRef],
            'payment_intent_data' => ['metadata' => ['order_ref' => $orderRef]],
        ];

        // Apply discount coupon when a promo code was used on this order.
        // The line items carry full unit prices; the coupon reduces the Stripe
        // collected total to match the stored order.total exactly.
        if ((float) $order->discount_amount > 0) {
            $coupon = $this->stripe->coupons->create([
                'amount_off' => (int) round((float) $order->discount_amount * 100),
                'currency'   => $currency,
                'duration'   => 'once',
                'name'       => $order->discount_label ?? 'Discount',
            ]);
            $payload['discounts'] = [['coupon' => $coupon->id]];
        }

        Log::info('Stripe quote-order checkout session creating', [
            'order_ref'   => $orderRef,
            'success_url' => $successUrl,
        ]);

        $session = $this->stripe->checkout->sessions->create($payload);

        return [
            'checkout_session_id' => $session->id,
            'checkout_url'        => $session->url,
        ];
    }

    private function lineItems(array $orderData): array
    {
        if (! empty($orderData['line_items']) && is_array($orderData['line_items'])) {
            return $orderData['line_items'];
        }

        if (empty($orderData['items']) || ! is_array($orderData['items'])) {
            throw new InvalidArgumentException('Stripe checkout requires at least one line item.');
        }

        $currency = strtolower((string) ($orderData['currency'] ?? config('services.stripe.currency', 'eur')));
        $lineItems = [];

        foreach ($orderData['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->itemName($item);
            $unitAmount = $this->unitAmount($item);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            $lineItems[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'product_data' => [
                        'name' => $name,
                    ],
                    'unit_amount' => $unitAmount,
                ],
                'quantity' => $quantity,
            ];
        }

        if ($lineItems === []) {
            throw new InvalidArgumentException('Stripe checkout requires at least one valid line item.');
        }

        return $lineItems;
    }

    private function itemName(array $item): string
    {
        $name = $item['name'] ?? $item['product_name'] ?? $item['description'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Stripe line items require a product name.');
        }

        return trim($name);
    }

    private function unitAmount(array $item): int
    {
        if (isset($item['unit_amount'])) {
            return (int) $item['unit_amount'];
        }

        $amount = $item['unit_price'] ?? $item['price'] ?? $item['amount'] ?? null;

        if (! is_numeric($amount) || (float) $amount < 0) {
            throw new InvalidArgumentException('Stripe line items require a non-negative unit amount.');
        }

        return (int) round((float) $amount * 100);
    }
}
