<?php

namespace App\Http\Controllers;

use App\Mail\OrderConfirmation;
use App\Mail\OrderReceived;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\InvoiceService;
use App\Services\PromoCodeService;
use App\Services\StripeService;
use App\Services\TaxService;
use App\Services\TradeDocumentService;
use App\Services\VatValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class PaymentController extends Controller
{
    public function __construct(
        private StripeService $stripeService,
        private VatValidationService $vatService,
        private TaxService $taxService,
        private PromoCodeService $promoService,
        private InvoiceService $invoiceService,
    ) {}

    /**
     * POST /api/v1/payments/create-session
     *
     * Saves a pending order, creates a Stripe Checkout Session, and returns the
     * hosted checkout URL for the frontend.
     *
     * Request body:
     * {
     *   "delivery": { "name", "email", "address", "city", "postalCode", "country", "phone" },
     *   "paymentMethod": "stripe",
     *   "vat_number": "DE...",   (optional)
     *   "items": [
     *     { "product": { "id", "brand", "name", "size", "price" }, "quantity": 4 }
     *   ]
     * }
     */
    public function createSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'delivery'               => ['required', 'array'],
            'delivery.name'          => ['required', 'string', 'max:200'],
            'delivery.email'         => ['required', 'email', 'max:255'],
            'delivery.address'       => ['required', 'string', 'max:300'],
            'delivery.city'          => ['required', 'string', 'max:100'],
            'delivery.postalCode'    => ['required', 'string', 'max:20'],
            'delivery.country'       => ['required', 'string', 'max:100'],
            'delivery.phone'         => ['sometimes', 'nullable', 'string', 'max:50'],
            'paymentMethod'          => ['sometimes', 'nullable', 'string', 'in:stripe,bank_transfer'],
            'payment_method'         => ['sometimes', 'nullable', 'string', 'in:stripe,bank_transfer'],
            'vat_number'             => ['sometimes', 'nullable', 'string', 'max:20'],
            'customer_type'          => ['sometimes', 'nullable', 'string', 'in:b2b,b2c'],
            'promo_code'             => ['sometimes', 'nullable', 'string', 'max:50'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.product'        => ['required', 'array'],
            'items.*.product.id'     => ['required', 'integer'],
            'items.*.product.brand'  => ['required', 'string', 'max:200'],
            'items.*.product.name'   => ['required', 'string', 'max:300'],
            'items.*.product.size'   => ['required', 'string', 'max:50'],
            'items.*.product.price'  => ['required', 'numeric', 'min:0'],
            'items.*.quantity'       => ['required', 'integer', 'min:1'],
        ]);

        $delivery = $validated['delivery'];
        $items    = $validated['items'];

        // Auth token customer_type wins; fall back to request body for guest B2B
        $customerType = $this->resolveCustomerType($request) ?? ($validated['customer_type'] ?? null);

        $vatNumber    = $validated['vat_number'] ?? null;
        $vatValid     = null;
        $vatValidBool = null;
        if ($vatNumber) {
            $vatResult    = $this->vatService->validate($vatNumber);
            $vatValid     = $vatResult['valid'] ? 1 : 0;
            $vatValidBool = (bool) $vatResult['valid'];
        }

        // EU VAT enforcement: B2B customers outside Germany must supply a valid VAT number
        if ($this->taxService->requiresEuVat($delivery['country'], $customerType)) {
            if (! $vatNumber) {
                return response()->json([
                    'message' => 'A valid EU VAT number is required for business purchases in EU member states.',
                    'errors'  => ['vat_number' => ['A valid EU VAT number is required for business purchases in EU member states.']],
                ], 422);
            }
            if (! $vatValidBool) {
                return response()->json([
                    'message' => 'A valid EU VAT number is required for business purchases in EU member states.',
                    'errors'  => ['vat_number' => ['Your VAT number could not be validated. Please check it and try again.']],
                ], 422);
            }
        }

        // Use DB prices to prevent client-side price manipulation
        $productIds = collect($items)->pluck('product.id')->unique()->values()->all();
        $products   = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $lineItems = [];
        $subtotal  = 0;

        foreach ($items as $item) {
            $productData = $item['product'];
            $productId   = $productData['id'];
            $quantity    = (int) $item['quantity'];
            $dbProduct   = $products->get($productId);

            $unitPrice = $dbProduct ? (float) $dbProduct->price : (float) $productData['price'];
            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;

            $lineItems[] = [
                'product_id' => $productId,
                'sku'        => $dbProduct?->sku,
                'brand'      => $dbProduct?->brand ?? $productData['brand'],
                'name'       => $productData['name'],
                'size'       => $productData['size'],
                'unit_price' => $unitPrice,
                'quantity'   => $quantity,
                'line_total' => $lineTotal,
            ];
        }

        // --- Promo code ---
        $promoCode      = isset($validated['promo_code']) ? strtoupper(trim((string) $validated['promo_code'])) : null;
        $discountAmount = 0.0;
        $discountLabel  = null;

        if ($promoCode) {
            $promotion = $this->promoService->resolve($promoCode);

            if (! $promotion) {
                return response()->json(['message' => 'Invalid or expired promo code.'], 422);
            }

            // B2B customers cannot use B2C-only promotions
            $target = $promotion->customer_type_target;
            if ($customerType === 'b2b' && $target === 'b2c') {
                return response()->json([
                    'message' => 'This promotion is only available for personal/B2C customers.',
                ], 422);
            }

            $discountAmount = $this->promoService->calculateDiscount($promotion, $lineItems);

            if ($discountAmount <= 0) {
                return response()->json([
                    'message' => 'Your cart contains no items eligible for promo code ' . $promoCode . '.',
                ], 422);
            }

            $discountLabel = $this->promoService->label($promotion);
        }

        // Calculate tax after discount — delivery_cost is always 0 on Stripe cart checkout
        $tax = $this->taxService->calculate($delivery['country'], $vatValidBool, $customerType);
        $taxableBase  = $subtotal - $discountAmount;
        $taxAmount    = round($taxableBase * $tax['tax_rate'] / 100, 2);
        $total        = $taxableBase + $taxAmount;

        $ref           = $this->generateRef();
        $paymentMethod = $validated['paymentMethod'] ?? $validated['payment_method'] ?? 'stripe';

        // --- Bank transfer: create order and return details without Stripe ---
        if ($paymentMethod === 'bank_transfer') {
            try {
                $order = DB::transaction(function () use (
                    $delivery, $lineItems, $subtotal, $total, $ref, $request,
                    $vatNumber, $vatValid, $tax, $taxAmount,
                    $promoCode, $discountAmount, $discountLabel
                ) {
                    $order = Order::create([
                        'ref'               => $ref,
                        'customer_name'     => $delivery['name'],
                        'customer_email'    => $delivery['email'],
                        'customer_phone'    => $delivery['phone'] ?? null,
                        'address'           => $delivery['address'],
                        'city'              => $delivery['city'],
                        'postal_code'       => $delivery['postalCode'],
                        'country'           => $delivery['country'],
                        'payment_method'    => 'bank_transfer',
                        'subtotal'          => $subtotal,
                        'delivery_cost'     => 0.00,
                        'discount_amount'   => $discountAmount,
                        'discount_label'    => $discountLabel,
                        'promo_code'        => $promoCode,
                        'total'             => $total,
                        'status'            => 'confirmed',
                        'payment_status'    => 'pending',
                        'mode'              => 'manual',
                        'ip_address'        => $request->ip(),
                        'vat_number'        => $vatNumber,
                        'vat_valid'         => $vatValid,
                        'tax_treatment'     => $tax['tax_treatment'],
                        'tax_rate'          => $tax['tax_rate'],
                        'tax_amount'        => $taxAmount,
                        'is_reverse_charge' => $tax['is_reverse_charge'],
                    ]);

                    foreach ($lineItems as $line) {
                        OrderItem::create(['order_id' => $order->id] + $line);
                    }

                    return $order;
                });

                $order->load('items');

                // Auto-generate proforma invoice for bank transfer orders
                try {
                    app(TradeDocumentService::class)->generateProformaForOrder($order);
                } catch (\Throwable $e) {
                    Log::warning('Proforma auto-generation failed after bank transfer order creation', [
                        'order_ref' => $order->ref,
                        'error'     => $e->getMessage(),
                    ]);
                }

                try {
                    Mail::to($order->customer_email)->send(new OrderConfirmation($order, null));
                    Log::info('Bank transfer payment instructions email sent', [
                        'ref'            => $ref,
                        'customer_email' => $order->customer_email,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Bank transfer order confirmation email failed', [
                        'ref'   => $ref,
                        'error' => $e->getMessage(),
                    ]);
                }

                $adminEmail = config('mail.order_email');
                if ($adminEmail) {
                    try {
                        Mail::to($adminEmail)->send(new OrderReceived($order));
                        Log::info('Bank transfer admin notification sent', [
                            'ref'         => $ref,
                            'admin_email' => $adminEmail,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Bank transfer admin notification failed', [
                            'ref'   => $ref,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                Log::info('Bank transfer order created', ['ref' => $ref, 'total' => $total]);

                return response()->json([
                    'data' => [
                        'provider'       => 'bank_transfer',
                        'order_ref'      => $ref,
                        'payment_status' => 'pending',
                        'bank_details'   => config('payment.bank_transfer'),
                    ],
                ], 201);
            } catch (\Throwable $e) {
                Log::error('Bank transfer order creation failed', ['error' => $e->getMessage(), 'ref' => $ref]);

                return response()->json([
                    'message' => 'Order creation failed. Please try again.',
                ], 502);
            }
        }

        try {
            $order = DB::transaction(function () use (
                $delivery, $lineItems, $subtotal, $total, $ref, $request,
                $vatNumber, $vatValid, $tax, $taxAmount,
                $promoCode, $discountAmount, $discountLabel
            ) {
                $order = Order::create([
                    'ref'               => $ref,
                    'customer_name'     => $delivery['name'],
                    'customer_email'    => $delivery['email'],
                    'customer_phone'    => $delivery['phone'] ?? null,
                    'address'           => $delivery['address'],
                    'city'              => $delivery['city'],
                    'postal_code'       => $delivery['postalCode'],
                    'country'           => $delivery['country'],
                    'payment_method'    => 'stripe',
                    'subtotal'          => $subtotal,
                    'delivery_cost'     => 0.00,
                    'discount_amount'   => $discountAmount,
                    'discount_label'    => $discountLabel,
                    'promo_code'        => $promoCode,
                    'total'             => $total,
                    'status'            => 'pending',
                    'payment_status'    => 'pending',
                    'mode'              => 'live',
                    'ip_address'        => $request->ip(),
                    'vat_number'        => $vatNumber,
                    'vat_valid'         => $vatValid,
                    'tax_treatment'     => $tax['tax_treatment'],
                    'tax_rate'          => $tax['tax_rate'],
                    'tax_amount'        => $taxAmount,
                    'is_reverse_charge' => $tax['is_reverse_charge'],
                ]);

                foreach ($lineItems as $line) {
                    OrderItem::create(['order_id' => $order->id] + $line);
                }

                return $order;
            });

            // Build Stripe line items: net product items + VAT as separate line (standard only)
            $currency    = strtolower((string) config('services.stripe.currency', 'eur'));
            $stripeItems = $lineItems;

            if ($taxAmount > 0) {
                $stripeItems[] = [
                    'name'       => 'VAT (' . number_format($tax['tax_rate'], 0) . '%)',
                    'unit_price' => $taxAmount,
                    'quantity'   => 1,
                ];
            }

            $result = $this->stripeService->createCheckoutSession([
                'ref'             => $ref,
                'order_ref'       => $ref,
                'customer_email'  => $delivery['email'],
                'currency'        => $currency,
                'items'           => $stripeItems,
                'discount_amount' => $discountAmount,
                'discount_label'  => $discountLabel,
            ]);

            $order->update(['payment_session_id' => $result['checkout_session_id']]);

            Log::info('Stripe checkout session created', [
                'ref'                 => $ref,
                'checkout_session_id' => $result['checkout_session_id'],
                'amount'              => $subtotal,
            ]);

            return response()->json([
                'data' => [
                    'provider'            => 'stripe',
                    'order_ref'           => $ref,
                    'checkout_session_id' => $result['checkout_session_id'],
                    'checkout_url'        => $result['checkout_url'],
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('createSession failed', ['error' => $e->getMessage(), 'ref' => $ref]);

            return response()->json([
                'message' => 'Payment gateway error. Please try again.',
            ], 502);
        }
    }

    /**
     * POST /api/v1/payments/tax-preview
     *
     * Returns the tax calculation that will be applied when the customer
     * proceeds to Stripe checkout — without creating any order or session.
     */
    public function taxPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items'                => ['required', 'array', 'min:1'],
            'items.*.price'        => ['required', 'numeric', 'min:0'],
            'items.*.quantity'     => ['required', 'integer', 'min:1'],
            'items.*.brand'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'items.*.product_id'   => ['sometimes', 'nullable', 'integer'],
            'delivery_cost'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'country'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'vat_number'           => ['sometimes', 'nullable', 'string', 'max:30'],
            'vat_valid'            => ['sometimes', 'nullable', 'boolean'],
            'customer_type'        => ['sometimes', 'nullable', 'string', 'in:b2b,b2c'],
            'promo_code'           => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $subtotalNet  = collect($validated['items'])->sum(fn ($i) => (float) $i['price'] * (int) $i['quantity']);
        $deliveryCost = (float) ($validated['delivery_cost'] ?? 0);

        // Determine VAT validity — explicit boolean wins; otherwise call VIES if number given
        $vatValidInput = $validated['vat_valid'] ?? null;
        $vatNumber     = $validated['vat_number'] ?? null;

        if ($vatValidInput !== null) {
            $vatValid = (bool) $vatValidInput;
        } elseif ($vatNumber) {
            $result   = $this->vatService->validate($vatNumber);
            $vatValid = $result['valid'] ?? false;
        } else {
            $vatValid = null;
        }

        // Authenticated customer's customer_type takes precedence over the request field
        $customerType = $this->resolveCustomerType($request) ?? ($validated['customer_type'] ?? null);

        // --- Promo code ---
        $promoCode      = isset($validated['promo_code']) ? strtoupper(trim((string) $validated['promo_code'])) : null;
        $discountAmount = 0.0;
        $discountLabel  = null;

        if ($promoCode) {
            $promotion = $this->promoService->resolve($promoCode);

            if (! $promotion) {
                return response()->json(['message' => 'Invalid or expired promo code.'], 422);
            }

            if ($customerType === 'b2b' && $promotion->customer_type_target === 'b2c') {
                return response()->json([
                    'message' => 'This promotion is only available for personal/B2C customers.',
                ], 422);
            }

            // Map preview items to the shape PromoCodeService expects.
            // Brand may be omitted by the frontend; fall back to a DB lookup via product_id.
            $previewProductIds = collect($validated['items'])->pluck('product_id')->filter()->unique()->values()->all();
            $previewBrands     = $previewProductIds ? Product::whereIn('id', $previewProductIds)->pluck('brand', 'id') : collect();

            $previewItems = array_map(fn ($i) => [
                'brand'      => $i['brand'] ?? $previewBrands->get($i['product_id'] ?? null),
                'unit_price' => (float) $i['price'],
                'quantity'   => (int) $i['quantity'],
            ], $validated['items']);

            $discountAmount = $this->promoService->calculateDiscount($promotion, $previewItems);

            if ($discountAmount <= 0) {
                return response()->json([
                    'message' => 'Your cart contains no items eligible for promo code ' . $promoCode . '.',
                ], 422);
            }

            $discountLabel = $this->promoService->label($promotion);
        }

        $tax         = $this->taxService->calculate($validated['country'] ?? null, $vatValid, $customerType);
        $taxableBase = $subtotalNet - $discountAmount + $deliveryCost;
        $taxAmount   = round($taxableBase * $tax['tax_rate'] / 100, 2);
        $total       = round($taxableBase + $taxAmount, 2);

        return response()->json([
            'data' => [
                'subtotal_net'      => round($subtotalNet, 2),
                'delivery_cost'     => round($deliveryCost, 2),
                'discount_amount'   => $discountAmount,
                'discount_label'    => $discountLabel,
                'promo_code'        => $promoCode,
                'tax_rate'          => $tax['tax_rate'],
                'tax_amount'        => $taxAmount,
                'tax_treatment'     => $tax['tax_treatment'],
                'is_reverse_charge' => $tax['is_reverse_charge'],
                'total'             => $total,
                'note'              => $tax['note'],
            ],
        ]);
    }

    /**
     * POST /api/v1/payments/webhook
     *
     * Handles Stripe webhook notifications for Checkout payments.
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            Log::error('Stripe webhook secret is not configured.');

            return response()->json(['message' => 'Stripe webhook is not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret
            );
        } catch (UnexpectedValueException $e) {
            Log::warning('Stripe webhook rejected: invalid payload', [
                'reason'    => $e->getMessage(),
                'ip'        => $request->ip(),
                'timestamp' => now()->toIso8601String(),
            ]);
            return response()->json(['message' => 'Invalid payload.'], 400);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook rejected: signature verification failed', [
                'reason'           => $e->getMessage(),
                'ip'               => $request->ip(),
                'sig_header_start' => substr((string) $request->header('Stripe-Signature', ''), 0, 40),
                'timestamp'        => now()->toIso8601String(),
            ]);
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $object = $this->stripeObjectToArray($event->data->object ?? []);
        $order = $this->resolveOrderFromStripeObject($object);

        Log::info('Stripe webhook received', [
            'event'     => $event->type,
            'order_ref' => $order?->ref,
            'object_id' => $object['id'] ?? null,
        ]);

        if (! $order && in_array($event->type, [
            'checkout.session.completed',
            'payment_intent.payment_failed',
            'charge.refunded',
        ], true)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->markOrderPaid($order, $object),
            'payment_intent.payment_failed' => $this->markOrderFailed($order),
            'charge.refunded' => $this->markOrderRefunded($order),
            default => null,
        };

        return response()->json(['message' => 'Webhook received.']);
    }

    private function resolveOrderFromStripeObject(array $object): ?Order
    {
        $checkoutSessionId = $object['id'] ?? null;
        if (is_string($checkoutSessionId) && str_starts_with($checkoutSessionId, 'cs_')) {
            $order = Order::where('payment_session_id', $checkoutSessionId)->first();
            if ($order) {
                return $order;
            }
        }

        $orderRef = $object['metadata']['order_ref'] ?? $object['client_reference_id'] ?? null;
        if (is_string($orderRef) && $orderRef !== '') {
            return Order::where('ref', $orderRef)->first();
        }

        return null;
    }

    private function markOrderPaid(?Order $order, array $object): void
    {
        if (! $order) {
            return;
        }

        // Idempotency guard — Stripe may retry webhooks; skip if already processed
        if ($order->payment_status === 'paid') {
            Log::info('Stripe webhook: order already paid, skipping duplicate', ['ref' => $order->ref]);
            return;
        }

        $order->update([
            'payment_status'     => 'paid',
            'payment_session_id' => $object['id'] ?? $order->payment_session_id,
            'status'             => 'confirmed',
        ]);

        $order->load('items');

        $invoice = $this->invoiceService->createForOrder($order);

        // Do not expose unreleased (reverse-charge) invoices in the confirmation email.
        // The customer will receive the invoice after signing the EU Entry Certificate.
        $invoiceForEmail = ($invoice && $order->is_reverse_charge) ? null : $invoice;

        try {
            Mail::to($order->customer_email)->send(new OrderConfirmation($order, $invoiceForEmail));
            Log::info('Stripe order confirmation email sent', [
                'ref'            => $order->ref,
                'customer_email' => $order->customer_email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe order confirmation email failed', [
                'ref'   => $order->ref,
                'error' => $e->getMessage(),
            ]);
        }

        $adminEmail = config('mail.order_email');
        if ($adminEmail) {
            try {
                Mail::to($adminEmail)->send(new OrderReceived($order));
                Log::info('Stripe admin order notification sent', [
                    'ref'         => $order->ref,
                    'admin_email' => $adminEmail,
                ]);
            } catch (\Throwable $e) {
                Log::error('Stripe admin order notification email failed', [
                    'ref'   => $order->ref,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function markOrderFailed(?Order $order): void
    {
        if (! $order) {
            return;
        }

        $order->update([
            'payment_status' => 'failed',
            'status'         => 'cancelled',
        ]);
    }

    private function markOrderRefunded(?Order $order): void
    {
        if (! $order) {
            return;
        }

        $order->update([
            'payment_status' => 'refunded',
        ]);
    }

    private function stripeObjectToArray(mixed $object): array
    {
        if (is_array($object)) {
            return $object;
        }

        if (is_object($object) && method_exists($object, 'toArray')) {
            return $object->toArray();
        }

        return [];
    }

    private function resolveCustomerType(Request $request): ?string
    {
        $raw = $request->bearerToken();
        if (! $raw) {
            return null;
        }

        $token = PersonalAccessToken::findToken($raw);
        if (! $token || $token->tokenable_type !== Customer::class) {
            return null;
        }

        return $token->tokenable?->customer_type;
    }

    private function generateRef(): string
    {
        $timestamp = strtoupper(base_convert(substr((string) now()->timestamp, -5), 10, 36));
        $rand      = strtoupper(Str::random(3));

        return "OKL-{$timestamp}{$rand}";
    }
}
