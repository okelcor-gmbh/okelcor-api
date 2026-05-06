<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Mail\OrderReceived;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\VatValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class OrderController extends Controller
{
    public function __construct(private VatValidationService $vatService) {}

    /**
     * GET /api/v1/orders?email=customer@example.com
     *
     * Returns orders for a given email address, with items.
     * Requires ?email= — returns empty list if omitted.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $orders = Order::with(['items', 'shipmentEvents'])
            ->where('customer_email', $request->email)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data'    => $orders->map(fn ($o) => $this->formatOrder($o))->values(),
            'meta'    => ['total' => $orders->count()],
            'message' => 'success',
        ]);
    }

    /**
     * GET /api/v1/orders/{ref}
     *
     * Returns the full current state of one order by its ref.
     * Used by the customer tracking page (fetched with cache: "no-store").
     */
    public function show(string $ref): JsonResponse
    {
        $order = Order::with(['items', 'shipmentEvents'])
            ->where('ref', $ref)
            ->firstOrFail();

        return response()->json([
            'data'    => $this->formatOrder($order),
            'message' => 'success',
        ]);
    }

    private function formatOrder(Order $o): array
    {
        return [
            'ref'               => $o->ref,
            'status'            => $o->status,
            'payment_status'    => $o->payment_status,
            'payment_method'    => $o->payment_method,
            'subtotal'          => (float) $o->subtotal,
            'delivery_cost'     => (float) $o->delivery_cost,
            'total'             => (float) $o->total,
            'carrier'            => $o->carrier,
            'tracking_number'    => $o->tracking_number,
            'container_number'   => $o->container_number,
            'estimated_delivery' => $o->estimated_delivery,
            'eta'                => $o->eta,
            'created_at'         => $o->created_at?->toIso8601String(),
            'shipment_events'    => $o->relationLoaded('shipmentEvents')
                ? $o->shipmentEvents->map(fn ($e) => [
                    'id'           => $e->id,
                    'event_date'   => $e->event_date?->toDateString(),
                    'location'     => $e->location,
                    'status_label' => $e->status_label,
                    'description'  => $e->description,
                    'created_at'   => $e->created_at?->toIso8601String(),
                ])->values()
                : [],
            'items'             => $o->items->map(fn ($i) => [
                'product_id'   => $i->product_id,
                'product_name' => $i->name,
                'brand'        => $i->brand,
                'size'         => $i->size,
                'sku'          => $i->sku,
                'quantity'     => $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'subtotal'     => (float) $i->line_total,
            ])->values(),
        ];
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $delivery  = $validated['delivery'];
        $items     = $validated['items'];

        // Strip VAT for individual (b2c) customers — they don't have a business VAT number
        $customer  = $this->resolveCustomerFromToken($request);
        $vatNumber = ($customer && $customer->customer_type === 'b2c')
            ? null
            : ($validated['vat_number'] ?? null);
        $vatValid  = null;

        if ($vatNumber) {
            $vatResult = $this->vatService->validate($vatNumber);
            $vatValid  = $vatResult['valid'] ? 1 : 0;
        }

        $fetAddon = $validated['fet_addon'] ?? null;
        $subtotal = collect($items)->sum(fn ($i) => $i['unit_price'] * $i['quantity']);
        if ($fetAddon) {
            $subtotal += $fetAddon['unit_price'] * $fetAddon['quantity'];
        }
        $total = $subtotal;
        $ref   = $this->generateRef();

        $order = DB::transaction(function () use ($delivery, $items, $fetAddon, $subtotal, $total, $ref, $request, $vatNumber, $vatValid) {
            $order = Order::create([
                'ref'            => $ref,
                'customer_name'  => $delivery['name'],
                'customer_email' => $delivery['email'],
                'customer_phone' => $delivery['phone'],
                'address'        => $delivery['address'],
                'city'           => $delivery['city'],
                'postal_code'    => $delivery['postal_code'],
                'country'        => $delivery['country'],
                'payment_method' => $request->payment_method,
                'subtotal'       => $subtotal,
                'delivery_cost'  => 0.00,
                'total'          => $total,
                'status'         => 'pending',
                'payment_status' => 'pending',
                'mode'           => 'manual',
                'ip_address'     => $request->ip(),
                'vat_number'     => $vatNumber,
                'vat_valid'      => $vatValid,
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $item['product_id'] ?? null,
                    'sku'        => $item['sku'],
                    'brand'      => $item['brand'],
                    'name'       => $item['name'],
                    'size'       => $item['size'],
                    'unit_price' => $item['unit_price'],
                    'quantity'   => $item['quantity'],
                    'line_total' => $item['unit_price'] * $item['quantity'],
                ]);
            }

            if ($fetAddon) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => null,
                    'sku'        => $fetAddon['sku'],
                    'brand'      => '',
                    'name'       => $fetAddon['product_name'],
                    'size'       => '',
                    'unit_price' => $fetAddon['unit_price'],
                    'quantity'   => $fetAddon['quantity'],
                    'line_total' => $fetAddon['unit_price'] * $fetAddon['quantity'],
                ]);
            }

            return $order;
        });

        $order->load('items');

        $adminEmail = env('ORDER_EMAIL');
        if ($adminEmail) {
            Mail::to($adminEmail)->send(new OrderReceived($order));
        }

        return response()->json([
            'data' => [
                'ref'     => $ref,
                'mode'    => 'manual',
                'message' => 'Order received. Our team will contact you to arrange payment.',
            ],
        ], 201);
    }

    public function mollieWebhook(Request $request): JsonResponse
    {
        $secret = config('services.mollie.webhook_secret');
        if ($secret && $request->header('X-Webhook-Secret') !== $secret) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $request->validate([
            'paymentId' => ['required', 'string'],
            'orderRef'  => ['required', 'string'],
            'status'    => ['required', 'string', 'in:paid,failed,expired,canceled,pending,open'],
        ]);

        $order = Order::where('ref', $request->orderRef)->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $statusMap = [
            'paid'     => ['payment_status' => 'paid',     'status' => 'processing'],
            'failed'   => ['payment_status' => 'failed',   'status' => 'cancelled'],
            'expired'  => ['payment_status' => 'expired',  'status' => 'cancelled'],
            'canceled' => ['payment_status' => 'canceled', 'status' => 'cancelled'],
            'pending'  => ['payment_status' => 'pending'],
            'open'     => ['payment_status' => 'open'],
        ];

        $order->update($statusMap[$request->status] ?? ['payment_status' => $request->status]);

        return response()->json(['message' => 'Webhook received.']);
    }

    private function resolveCustomerFromToken(Request $request): ?Customer
    {
        $raw = $request->bearerToken();
        if (! $raw) {
            return null;
        }

        $token = PersonalAccessToken::findToken($raw);
        if (! $token || $token->tokenable_type !== Customer::class) {
            return null;
        }

        return $token->tokenable;
    }

    private function generateRef(): string
    {
        $timestamp = strtoupper(base_convert(substr((string) now()->timestamp, -5), 10, 36));
        $rand      = strtoupper(Str::random(3));

        return "OKL-{$timestamp}{$rand}";
    }
}
