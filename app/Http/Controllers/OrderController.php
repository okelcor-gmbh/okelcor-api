<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Mail\OrderReceived;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\EuDeclarationService;
use App\Services\TradeDocumentService;
use App\Services\VatValidationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class OrderController extends Controller
{
    public function __construct(
        private VatValidationService $vatService,
        private EuDeclarationService $declarationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $email = $request->user()->email;

        $orders = Order::with(['items', 'shipmentEvents', 'euDeclaration', 'tradeDocuments', 'invoice'])
            ->where('customer_email', $email)
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
     * Auto-creates a pending eu_declarations row for qualifying reverse-charge
     * orders that pre-date Phase 2B-2 deployment, so declaration_status is
     * always 'pending' (never null) when declaration_required is true.
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $order = Order::with(['items', 'shipmentEvents', 'euDeclaration', 'tradeDocuments', 'invoice'])
            ->where('ref', $ref)
            ->where('customer_email', $request->user()->email)
            ->firstOrFail();

        // Back-fill missing declaration row for pre-2B-2 reverse-charge orders.
        if ($order->is_reverse_charge && ! $order->euDeclaration) {
            if ($this->declarationService->shouldRequireForOrder($order)) {
                $invoice     = Invoice::where('order_ref', $order->ref)->first();
                $declaration = $this->declarationService->createForOrder($order, $invoice);
                $order->setRelation('euDeclaration', $declaration);
            }
        }

        return response()->json([
            'data'    => $this->formatOrder($order),
            'message' => 'success',
        ]);
    }

    private function formatOrder(Order $o): array
    {
        // Invoice state for the order — lets the FE show "download invoice" vs
        // "invoice pending EU entry certificate" without a second request.
        $invoice         = $o->relationLoaded('invoice') ? $o->invoice : null;
        $invoiceReleased = $invoice && $invoice->released_at !== null;
        // Held: a reverse-charge order whose invoice isn't released yet (or is
        // paid with no released invoice yet) — waiting on EU cert acknowledgement.
        $invoicePendingRelease = $o->is_reverse_charge === true
            && ! $invoiceReleased
            && ($invoice !== null || $o->payment_status === 'paid');

        return [
            'ref'               => $o->ref,
            'status'            => $o->status,
            'payment_status'    => $o->payment_status,
            'payment_method'    => $o->payment_method,
            'subtotal'          => (float) $o->subtotal,
            'delivery_cost'     => (float) $o->delivery_cost,
            'total'             => (float) $o->total,
            'carrier'            => $o->carrier,
            'carrier_type'       => $o->carrier_type,
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
            // Order confirmation acceptance — customer-visible status
            'customer_acceptance_status' => $o->customer_acceptance_status ?? 'pending',
            'customer_accepted_at'       => $o->customer_accepted_at?->toIso8601String(),

            // Payment milestones — customer-visible progress
            'payment_stage'        => $o->payment_stage ?? 'pending_proforma',
            'deposit_amount'       => $o->deposit_amount !== null ? (float) $o->deposit_amount : null,
            'deposit_paid_at'      => $o->deposit_paid_at?->toIso8601String(),
            'balance_amount'       => $o->balance_amount !== null ? (float) $o->balance_amount : null,
            'balance_paid_at'      => $o->balance_paid_at?->toIso8601String(),

            // EU entry certificate — customer-visible status + download availability
            'declaration_required'           => $o->is_reverse_charge === true,
            'declaration_status'             => $o->relationLoaded('euDeclaration') ? $o->euDeclaration?->status : null,
            'declaration_signed_at'          => $o->relationLoaded('euDeclaration') ? $o->euDeclaration?->signed_at?->toIso8601String() : null,
            'declaration_signed_name'        => $o->relationLoaded('euDeclaration') ? $o->euDeclaration?->signed_name : null,
            'declaration_download_available' => $o->relationLoaded('euDeclaration')
                && $o->euDeclaration?->pdf_path !== null
                && in_array($o->euDeclaration?->status, ['signed', 'acknowledged']),

            // Invoice — customer-visible state + download availability.
            // A released invoice is always downloadable: the order exists here, so
            // the download endpoint self-heals (regenerates) any missing PDF.
            'invoice_number'          => $invoiceReleased ? $invoice->invoice_number : null,
            'invoice_available'       => $invoiceReleased,
            'invoice_pending_release' => $invoicePendingRelease,
            'invoice_download_url'    => $invoiceReleased
                ? route('invoices.download', $invoice->id)
                : null,

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

            // Trade documents — issued/sent documents visible to the customer
            'trade_documents' => $o->relationLoaded('tradeDocuments')
                ? $o->tradeDocuments
                    ->filter(fn ($d) => in_array($d->status, ['issued', 'sent'], true)
                        && in_array($d->type, ['order_confirmation', 'proforma', 'commercial_invoice', 'packing_list', 'delivery_note', 'shipment_document'], true)
                        && ! ($d->type === 'proforma' && ($o->customer_acceptance_status ?? 'pending') !== 'accepted'))
                    ->map(fn ($d) => [
                        'id'                => $d->id,
                        'type'              => $d->type,
                        'type_label'        => $d->type_label,
                        'number'            => $d->number,
                        'status'            => $d->status,
                        'has_pdf'           => (bool) $d->getRawOriginal('pdf_path'),
                        'has_file'          => (bool) $d->getRawOriginal('file_path'),
                        'issued_at'         => $d->issued_at?->toIso8601String(),
                        'sent_at'           => $d->sent_at?->toIso8601String(),
                        'original_filename' => $d->original_filename,
                        'mime_type'         => $d->mime_type,
                        'file_size'         => $d->file_size,
                    ])->values()
                : [],
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

        // Auto-generate order confirmation (AB) — proforma must not be issued before customer accepts
        try {
            app(TradeDocumentService::class)->generateOrderConfirmationForOrder($order, null);
        } catch (\Throwable $e) {
            Log::warning('Order confirmation auto-generation failed after manual order creation', [
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
            ]);
        }

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
