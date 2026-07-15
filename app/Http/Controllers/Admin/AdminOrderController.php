<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Services\AdminAuditLogger;
use App\Services\CustomerHealthService;
use App\Services\CustomerNotifier;
use App\Services\InvoiceService;
use App\Services\WhatsAppNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('customer_email')) {
            $query->where('customer_email', $request->customer_email);
        }

        if ($request->filled('customer_id')) {
            $email = Customer::where('id', $request->integer('customer_id'))->value('email');
            $query->where('customer_email', $email ?? '');
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->where('ref', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_email', 'like', "%{$q}%");
            });
        }

        $perPage   = min((int) $request->input('per_page', 20), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data'    => $paginated->map(fn ($o) => $this->formatOrderList($o)),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::with(['items', 'logs', 'shipmentEvents', 'euDeclaration', 'tradeDocuments'])->findOrFail($id);

        return response()->json([
            'data'    => $this->formatOrderDetail($order),
            'message' => 'success',
        ]);
    }

    /**
     * POST /api/v1/admin/orders
     *
     * Records an order that already happened outside the system — for
     * existing Okelcor customers being onboarded with prior shipment/order
     * history. Distinct from the public checkout flow (no payment session
     * involved) and from CSV import (one order at a time, entered by hand).
     *
     * Because Order links to Customer by e-mail (not a foreign key), the
     * moment this order's customer_email matches an onboarded customer's
     * e-mail, it appears automatically in that customer's portal — no
     * further linking step is needed.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id'        => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name'      => ['required_without:customer_id', 'string', 'max:200'],
            'customer_email'     => ['required_without:customer_id', 'string', 'email', 'max:255'],
            'customer_phone'     => ['nullable', 'string', 'max:50'],
            'address'            => ['nullable', 'string', 'max:255'],
            'city'               => ['nullable', 'string', 'max:100'],
            'postal_code'        => ['nullable', 'string', 'max:20'],
            'country'            => ['nullable', 'string', 'max:100'],
            'ref'                => ['nullable', 'string', 'max:30', 'unique:orders,ref'],
            'order_date'         => ['nullable', 'date'],
            'status'             => ['required', Rule::in(['pending', 'confirmed', 'awaiting_proforma', 'processing', 'shipped', 'delivered', 'cancelled'])],
            'payment_status'     => ['required', Rule::in(['pending', 'paid', 'failed', 'refunded'])],
            'payment_stage'      => ['nullable', Rule::in(['pending_proforma', 'deposit_requested', 'deposit_paid', 'balance_due', 'balance_paid', 'shipment_released'])],
            'carrier'            => ['nullable', 'string', 'max:100'],
            'carrier_type'       => ['nullable', Rule::in(['sea', 'air', 'dhl', 'road', 'truck'])],
            'tracking_number'    => ['nullable', 'string', 'max:100'],
            'container_number'   => ['nullable', 'string', 'max:30'],
            'estimated_delivery' => ['nullable', 'date'],
            'admin_notes'        => ['nullable', 'string'],
            'items'              => ['nullable', 'array'],
            'items.*.sku'        => ['required_with:items', 'string', 'max:100'],
            'items.*.name'       => ['required_with:items', 'string', 'max:255'],
            'items.*.brand'      => ['nullable', 'string', 'max:100'],
            'items.*.size'       => ['nullable', 'string', 'max:50'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.quantity'   => ['required_with:items', 'integer', 'min:1'],
            'total'              => ['required_without:items', 'numeric', 'min:0'],
        ]);

        $customerName  = $data['customer_name'] ?? null;
        $customerEmail = $data['customer_email'] ?? null;

        if (! empty($data['customer_id'])) {
            $customer      = Customer::findOrFail($data['customer_id']);
            $customerName  = $customerName ?? $customer->full_name;
            $customerEmail = $customerEmail ?? $customer->email;
        }

        $items    = $data['items'] ?? [];
        $subtotal = collect($items)->sum(fn ($i) => $i['unit_price'] * $i['quantity']);
        $total    = $items ? $subtotal : (float) $data['total'];

        // A fully-paid historical order defaults to the final payment-milestone
        // stage so document upload / visibility (both gated on payment_stage)
        // isn't blocked behind a milestone that no longer applies to something
        // that already happened. Admin can override for an order still mid-flight.
        $paymentStage = $data['payment_stage'] ?? match ($data['payment_status']) {
            'paid'  => 'balance_paid',
            default => 'pending_proforma',
        };

        $order = DB::transaction(function () use ($data, $customerName, $customerEmail, $items, $subtotal, $total, $paymentStage) {
            $order = Order::create([
                'ref'                => $data['ref'] ?? $this->generateRef(),
                'source'             => 'admin_manual',
                'customer_name'      => $customerName,
                'customer_email'     => $customerEmail,
                'customer_phone'     => $data['customer_phone'] ?? null,
                'address'            => $data['address'] ?? null,
                'city'               => $data['city'] ?? null,
                'postal_code'        => $data['postal_code'] ?? null,
                'country'            => $data['country'] ?? null,
                'subtotal'           => $subtotal ?: $total,
                'delivery_cost'      => 0,
                'total'              => $total,
                'status'             => $data['status'],
                'payment_status'     => $data['payment_status'],
                'payment_stage'      => $paymentStage,
                'mode'               => 'manual',
                'carrier'            => $data['carrier'] ?? null,
                'carrier_type'       => $data['carrier_type'] ?? null,
                'tracking_number'    => $data['tracking_number'] ?? null,
                'container_number'   => $data['container_number'] ?? null,
                'estimated_delivery' => $data['estimated_delivery'] ?? null,
                'admin_notes'        => $data['admin_notes'] ?? null,
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $item['product_id'] ?? null,
                    'sku'        => $item['sku'],
                    'brand'      => $item['brand'] ?? '',
                    'name'       => $item['name'],
                    'size'       => $item['size'] ?? '',
                    'unit_price' => $item['unit_price'],
                    'quantity'   => $item['quantity'],
                    'line_total' => $item['unit_price'] * $item['quantity'],
                ]);
            }

            // Backdate the record to when the order actually happened, so it
            // sorts correctly in both the admin and customer order lists.
            if (! empty($data['order_date'])) {
                $order->forceFill(['created_at' => $data['order_date']])->save();
            }

            return $order;
        });

        $this->writeLog($request, $order, 'created', ['notes' => 'Historical order recorded by admin.']);

        $order->load(['items', 'logs', 'euDeclaration', 'tradeDocuments']);

        return response()->json([
            'data'    => $this->formatOrderDetail($order),
            'message' => 'Order recorded successfully.',
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'             => ['required', Rule::in(['pending', 'confirmed', 'awaiting_proforma', 'processing', 'shipped', 'delivered', 'cancelled'])],
            'carrier'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'carrier_type'       => ['sometimes', 'nullable', Rule::in(['sea', 'air', 'dhl', 'road', 'truck'])],
            'tracking_number'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'container_number'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'estimated_delivery' => ['sometimes', 'nullable', 'date'],
            'eta'                => ['sometimes', 'nullable', 'date'],
            'admin_notes'        => ['sometimes', 'nullable', 'string'],
        ]);

        $order          = Order::findOrFail($id);
        $previousStatus = $order->status;

        if ($request->input('status') === 'cancelled' && in_array($order->status, ['cancelled', 'delivered'], true)) {
            return response()->json([
                'message' => 'Order cannot be cancelled in its current state.',
            ], 409);
        }

        $order->update($request->only(['status', 'carrier', 'carrier_type', 'tracking_number', 'container_number', 'estimated_delivery', 'eta', 'admin_notes']));
        $order->load(['items', 'logs', 'euDeclaration', 'tradeDocuments']);

        $this->logStatusChange($request, $order, $previousStatus);
        $this->logTrackingChange($request, $order);
        $this->notifyShipmentStatus($order, $previousStatus);

        return response()->json([
            'data'    => $this->formatOrderDetail($order),
            'message' => 'success',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if ((string) $request->input('confirm_ref') !== (string) $order->ref) {
            return response()->json([
                'message' => 'Order reference confirmation does not match.',
            ], 422);
        }

        if ($order->payment_status === 'paid') {
            return response()->json([
                'message' => 'Cannot delete a paid order. Change payment status first.',
            ], 409);
        }

        // Log before deletion — order record still exists, FK will nullify after delete.
        $this->writeLog($request, $order, 'deleted', ['old_value' => $order->status]);
        AdminAuditLogger::critical('order_deleted', "Order deleted: {$order->ref}", $request, $request->user(), [
            'order_id'  => $order->id,
            'order_ref' => $order->ref,
            'status'    => $order->status,
        ]);

        $order->items()->delete();
        $order->delete();

        return response()->json(['message' => 'Order deleted.'], 200);
    }

    /**
     * PATCH /api/v1/admin/orders/{id}/status
     *
     * Lightweight status + shipment update used by the admin panel.
     * All shipment fields are optional — only provided fields are updated.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'             => ['required', Rule::in(['pending', 'confirmed', 'awaiting_proforma', 'processing', 'shipped', 'delivered', 'cancelled'])],
            'carrier'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'carrier_type'       => ['sometimes', 'nullable', Rule::in(['sea', 'air', 'dhl', 'road', 'truck'])],
            'tracking_number'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'container_number'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'estimated_delivery' => ['sometimes', 'nullable', 'date'],
            'eta'                => ['sometimes', 'nullable', 'date'],
        ]);

        $order          = Order::findOrFail($id);
        $previousStatus = $order->status;

        if ($request->input('status') === 'cancelled' && in_array($order->status, ['cancelled', 'delivered'], true)) {
            return response()->json([
                'message' => 'Order cannot be cancelled in its current state.',
            ], 409);
        }

        $order->update($request->only(['status', 'carrier', 'carrier_type', 'tracking_number', 'container_number', 'estimated_delivery', 'eta']));

        $this->logStatusChange($request, $order, $previousStatus);
        $this->logTrackingChange($request, $order);
        $this->notifyShipmentStatus($order, $previousStatus);

        return response()->json([
            'data'    => [
                'id'                 => $order->id,
                'ref'                => $order->ref,
                'status'             => $order->status,
                'carrier'            => $order->carrier,
                'carrier_type'       => $order->carrier_type,
                'tracking_number'    => $order->tracking_number,
                'container_number'   => $order->container_number,
                'estimated_delivery' => $order->estimated_delivery,
                'eta'                => $order->eta,
            ],
            'meta'    => [],
            'message' => 'Status updated successfully.',
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/mark-paid
     *
     * Manually confirm a bank transfer payment after the admin has verified
     * the receipt in Wise/bank account.
     */
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'confirmation'      => ['required', 'accepted'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:200'],
            'admin_note'        => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $order = Order::with('items')->findOrFail($id);

        if ($order->payment_method !== 'bank_transfer') {
            return response()->json([
                'message' => 'Only bank_transfer orders can be manually confirmed.',
            ], 422);
        }

        if ($order->payment_status !== 'pending') {
            return response()->json([
                'message' => 'Order payment is not pending.',
                'data'    => ['payment_status' => $order->payment_status],
            ], 409);
        }

        $order->update([
            'payment_status' => 'paid',
            'status'         => 'confirmed',
        ]);

        $fresh = $order->fresh(['items']);

        // Health/risk feeds off completed-order count — keep it current, not
        // just recomputed whenever an admin happens to click "recalculate".
        app(CustomerHealthService::class)->recalculateForEmail($fresh->customer_email, $request->user());

        // Invoice — idempotent; won't duplicate if one already exists
        $invoice = app(InvoiceService::class)->createForOrder($fresh);

        // Do not expose unreleased (reverse-charge) invoices in the confirmation email.
        $invoiceForEmail = ($invoice && $fresh->is_reverse_charge) ? null : $invoice;

        // Customer confirmation email
        try {
            Mail::to($fresh->customer_email)->send(new OrderConfirmation($fresh, $invoiceForEmail));
            Log::info('Bank transfer payment confirmation email sent', [
                'order_ref' => $fresh->ref,
                'email'     => $fresh->customer_email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Bank transfer payment confirmation email failed', [
                'order_ref' => $fresh->ref,
                'error'     => $e->getMessage(),
            ]);
        }

        // In-app twin — payment received, order confirmed.
        CustomerNotifier::notifyByEmail(
            $fresh->customer_email,
            'order_placed',
            "Payment received for order {$fresh->ref}",
            "Thank you — we've confirmed your payment and your order is now being processed.",
            [
                'severity'     => 'success',
                'action_url'   => "/account/orders/{$fresh->ref}",
                'related_type' => 'order',
                'related_id'   => $fresh->ref,
                'email_sent'   => true,
                'metadata'     => ['stage' => 'paid', 'order_ref' => $fresh->ref],
            ]
        );

        // Audit log
        $noteParts = array_filter([
            $request->filled('payment_reference') ? 'Payment reference: ' . $request->payment_reference : null,
            $request->filled('admin_note') ? $request->admin_note : null,
        ]);

        $this->writeLog($request, $fresh, 'payment_status_changed', [
            'old_value' => 'pending',
            'new_value' => 'paid',
            'notes'     => implode(' | ', $noteParts) ?: 'Bank transfer payment confirmed by admin.',
        ]);

        return response()->json([
            'data' => [
                'id'             => $fresh->id,
                'order_ref'      => $fresh->ref,
                'payment_status' => $fresh->payment_status,
                'status'         => $fresh->status,
                'invoice_number' => $invoice?->invoice_number,
                'invoice_pdf'    => $invoice?->pdf_url
                    ? url(\Illuminate\Support\Facades\Storage::url($invoice->pdf_url))
                    : null,
            ],
            'message' => 'Payment confirmed successfully.',
        ]);
    }

    /**
     * PATCH /api/v1/admin/orders/{id}/financials
     *
     * Correct a financial field on an order (e.g. wrong delivery fee entered at checkout).
     * Recalculates total as: old_total − old_delivery_cost + new_delivery_cost.
     * Always requires a reason for the audit log.
     */
    public function patchFinancials(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'reason'       => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $order = Order::findOrFail($id);

        if ($order->isFinancialsLocked()) {
            return response()->json([
                'message'           => 'Order financials are locked because a commercial document has been issued. Use the revision request workflow.',
                'code'              => 'financials_locked',
                'requires_supersede' => true,
            ], 423);
        }

        $oldDeliveryCost = (float) $order->delivery_cost;
        $oldTotal        = (float) $order->total;
        $newDeliveryCost = (float) $request->input('delivery_fee');

        // Surgical recalculation: swap only the delivery cost component
        $newTotal = round($oldTotal - $oldDeliveryCost + $newDeliveryCost, 2);

        $order->update([
            'delivery_cost' => $newDeliveryCost,
            'total'         => $newTotal,
        ]);

        $this->writeLog($request, $order, 'financial_corrected', [
            'old_value' => "delivery_cost={$oldDeliveryCost}, total={$oldTotal}",
            'new_value' => "delivery_cost={$newDeliveryCost}, total={$newTotal}",
            'notes'     => $request->input('reason'),
        ]);

        return response()->json([
            'data' => [
                'id'               => $order->id,
                'order_ref'        => $order->ref,
                'delivery_cost'    => (float) $order->delivery_cost,
                'total'            => (float) $order->total,
                'old_delivery_cost' => $oldDeliveryCost,
                'old_total'         => $oldTotal,
            ],
            'message' => 'Order financials updated successfully.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Logging helpers
    // -------------------------------------------------------------------------

    private function logStatusChange(Request $request, Order $order, string $previousStatus): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $action = $order->status === 'cancelled' ? 'cancelled' : 'status_changed';

        $this->writeLog($request, $order, $action, [
            'old_value' => $previousStatus,
            'new_value' => $order->status,
        ]);
    }

    /**
     * In-app twin for the shipment lifecycle. Fires once when an order
     * transitions INTO 'shipped' or 'delivered'. No email mailable exists for
     * these today, so this is in-app only; dedupe (stage = status) keeps it to a
     * single row per stage per order. Guest orders resolve to null and no-op.
     */
    private function notifyShipmentStatus(Order $order, string $previousStatus): void
    {
        if (! $order->wasChanged('status') || ! in_array($order->status, ['shipped', 'delivered'], true)) {
            return;
        }

        $hasLiveTracking = (bool) $order->carrier && ((bool) $order->tracking_number || (bool) $order->container_number);

        if ($order->status === 'shipped') {
            $type     = 'order_shipped';
            $title    = "Order {$order->ref} has shipped";
            $severity = 'info';
            $trackingSuffix = $order->tracking_number ? " Tracking number: {$order->tracking_number}." : '';
            // "Track it live" only when a carrier + tracking number are actually set.
            $body = $hasLiveTracking
                ? "Your order is on its way — track it live in your account.{$trackingSuffix}"
                : "Your order is on its way." . ($trackingSuffix ?: ' Tracking details will follow shortly.');
        } else {
            $type     = 'order_delivered';
            $title    = "Order {$order->ref} delivered";
            $severity = 'success';
            $body     = 'Your order has been delivered. Thank you for choosing Okelcor.';
        }

        CustomerNotifier::notifyByEmail(
            $order->customer_email,
            $type,
            $title,
            $body,
            [
                'severity'     => $severity,
                'action_url'   => "/account/orders/{$order->ref}",
                'related_type' => 'order',
                'related_id'   => $order->ref,
                'metadata'     => [
                    'stage'         => $order->status,
                    'order_ref'     => $order->ref,
                    'live_tracking' => $order->status === 'shipped' ? $hasLiveTracking : false,
                ],
            ]
        );

        // WhatsApp twin — opt-in gated (CustomerNotifier::wantsWhatsApp) and a
        // no-op until the matching template is approved in Meta Business
        // Manager (see WHATSAPP_SETUP.md). Guest/manual orders with no
        // matching customer account are skipped, same as the e-mail path
        // above would be if there were no address at all.
        $customer = Customer::where('email', $order->customer_email)->first();
        if ($customer) {
            WhatsAppNotifier::notifyTemplate(
                $customer,
                $type,
                [$order->ref, $order->tracking_number ?: 'N/A'],
                null,
                $order->id
            );
        }
    }

    private function logTrackingChange(Request $request, Order $order): void
    {
        if (! $order->wasChanged(['carrier', 'tracking_number', 'container_number', 'estimated_delivery', 'eta'])) {
            return;
        }

        $this->writeLog($request, $order, 'tracking_updated', [
            'notes' => 'Tracking fields updated.',
        ]);
    }

    /**
     * Same format as the public OrderController's ref generator
     * (OKL-XXXXX), kept in sync so refs look identical regardless of origin.
     */
    private function generateRef(): string
    {
        $timestamp = strtoupper(base_convert(substr((string) now()->timestamp, -5), 10, 36));
        $rand      = strtoupper(Str::random(3));

        return "OKL-{$timestamp}{$rand}";
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

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function formatOrderList(Order $o): array
    {
        return [
            'id'             => $o->id,
            'order_ref'      => $o->ref,
            'source'         => $o->source ?? 'website',
            'customer_name'  => $o->customer_name,
            'customer_email' => $o->customer_email,
            'total'          => (float) $o->total,
            'status'         => $o->status,
            'payment_status' => $o->payment_status,
            'payment_method' => $o->payment_method,
            'created_at'     => $o->created_at?->toIso8601String(),
        ];
    }

    private function formatOrderDetail(Order $o): array
    {
        return [
            'id'                 => $o->id,
            'order_ref'          => $o->ref,
            'customer_name'      => $o->customer_name,
            'customer_email'     => $o->customer_email,
            'phone'              => $o->customer_phone,
            'company_name'       => null,
            'address'            => trim(implode(', ', array_filter([$o->address, $o->city, $o->postal_code]))),
            'country'            => $o->country,
            'total'              => (float) $o->total,
            'status'             => $o->status,
            'payment_method'     => $o->payment_method,
            'notes'              => $o->admin_notes,
            'carrier'            => $o->carrier,
            'carrier_type'       => $o->carrier_type,
            'tracking_number'    => $o->tracking_number,
            'container_number'   => $o->container_number,
            'tracking_status'    => $o->tracking_status,
            'estimated_delivery' => $o->estimated_delivery,
            'eta'                => $o->eta,
            'payment_status'     => $o->payment_status,
            'payment_session_id' => $o->payment_session_id,
            'created_at'         => $o->created_at?->toIso8601String(),
            'updated_at'         => $o->updated_at?->toIso8601String(),
            'items'              => $o->items->map(fn ($i) => [
                'id'           => $i->id,
                'product_id'   => $i->product_id,
                'product_name' => $i->name,
                'brand'        => $i->brand,
                'size'         => $i->size,
                'sku'          => $i->sku,
                'quantity'     => $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'subtotal'     => (float) $i->line_total,
            ])->values(),
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
            'logs'               => $o->relationLoaded('logs')
                ? $o->logs->map(fn ($l) => [
                    'id'               => $l->id,
                    'action'           => $l->action,
                    'old_value'        => $l->old_value,
                    'new_value'        => $l->new_value,
                    'notes'            => $l->notes,
                    'admin_user_email' => $l->admin_user_email,
                    'ip_address'       => $l->ip_address,
                    'created_at'       => $l->created_at?->toIso8601String(),
                ])->values()
                : [],

            // Customer acceptance
            'customer_acceptance_status'           => $o->customer_acceptance_status ?? 'pending',
            'customer_accepted_at'                 => $o->customer_accepted_at?->toIso8601String(),
            'customer_acceptance_note'             => $o->customer_acceptance_note,
            'acceptance_token_expires_at'          => $o->acceptance_token_expires_at?->toIso8601String(),
            // token itself only included while pending (invalidated on accept/reject)
            'acceptance_token'                     => ($o->customer_acceptance_status ?? 'pending') === 'pending'
                                                        ? $o->acceptance_token
                                                        : null,

            // Financial lock
            'financials_locked'                    => $o->isFinancialsLocked(),
            'financials_locked_at'                 => $o->financials_locked_at?->toIso8601String(),
            'financials_lock_reason'               => $o->financials_lock_reason,
            'financials_revision_required'         => (bool) $o->financials_revision_required,
            'financials_revision_reason'           => $o->financials_revision_reason,
            'financials_revision_requested_at'     => $o->financials_revision_requested_at?->toIso8601String(),
            'financials_revision_changes'          => $o->financials_revision_changes,

            // Payment milestones
            'payment_stage'                        => $o->payment_stage ?? 'pending_proforma',
            'deposit_percent'                      => (float) ($o->deposit_percent ?? 50),
            'deposit_amount'                       => $o->deposit_amount !== null ? (float) $o->deposit_amount : null,
            'deposit_paid_at'                      => $o->deposit_paid_at?->toIso8601String(),
            'balance_amount'                       => $o->balance_amount !== null ? (float) $o->balance_amount : null,
            'balance_paid_at'                      => $o->balance_paid_at?->toIso8601String(),
            'shipment_released_at'                 => $o->shipment_released_at?->toIso8601String(),
            'shipment_release_note'                => $o->shipment_release_note,
            'deposit_requested_email_sent_at'      => $o->deposit_requested_email_sent_at?->toIso8601String(),
            'deposit_paid_email_sent_at'           => $o->deposit_paid_email_sent_at?->toIso8601String(),
            'balance_due_email_sent_at'            => $o->balance_due_email_sent_at?->toIso8601String(),
            'balance_paid_email_sent_at'           => $o->balance_paid_email_sent_at?->toIso8601String(),
            'shipment_released_email_sent_at'      => $o->shipment_released_email_sent_at?->toIso8601String(),

            // eBay order metadata (only populated when source = 'ebay')
            'source'                               => $o->source ?? 'website',
            'ebay_order_id'                        => $o->ebay_order_id,
            'ebay_buyer_username'                  => $o->ebay_buyer_username,
            'ebay_order_status'                    => $o->ebay_order_status,
            'ebay_payment_status'                  => $o->ebay_payment_status,
            'ebay_fulfillment_status'              => $o->ebay_fulfillment_status,
            'ebay_last_synced_at'                  => $o->ebay_last_synced_at?->toIso8601String(),
            'ebay_raw_summary'                     => $o->source === 'ebay' ? $o->ebay_raw_summary : null,

            // EU entry certificate
            'declaration_required'  => $o->is_reverse_charge === true,
            'declaration_status'    => $o->relationLoaded('euDeclaration') ? $o->euDeclaration?->status : null,
            'declaration_id'        => $o->relationLoaded('euDeclaration') ? $o->euDeclaration?->id : null,
            'declaration_signed_at' => $o->relationLoaded('euDeclaration') ? $o->euDeclaration?->signed_at?->toIso8601String() : null,

            // Trade documents
            'trade_documents' => $o->relationLoaded('tradeDocuments')
                ? $o->tradeDocuments->map(fn ($d) => [
                    'id'        => $d->id,
                    'type'      => $d->type,
                    'number'    => $d->number,
                    'status'    => $d->status,
                    'has_pdf'   => (bool) $d->getRawOriginal('pdf_path'),
                    'has_file'  => (bool) $d->getRawOriginal('file_path'),
                    'issued_at' => $d->issued_at?->toIso8601String(),
                ])->values()
                : [],
        ];
    }
}
