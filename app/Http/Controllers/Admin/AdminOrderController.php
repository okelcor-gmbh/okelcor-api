<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use App\Services\AdminAuditLogger;
use App\Services\CurrencyConversionService;
use App\Services\CustomerHealthService;
use App\Services\CustomerNotifier;
use App\Services\InvoiceService;
use App\Services\OrderSignoffService;
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
    /**
     * How the order list may be ordered. Served in `meta.sorts` so the control
     * is generated from this rather than from a hardcoded copy that can drift —
     * the same reason the campaign block catalogue is served rather than
     * duplicated.
     *
     * `newest` stays the default: it is what this endpoint has always done, and
     * every existing caller expects it.
     */
    public const SORTS = [
        'newest'     => 'Newest first',
        'oldest'     => 'Oldest first',
        'total_high' => 'Largest value first',
        'total_low'  => 'Smallest value first',
        'updated'    => 'Recently updated first',
    ];


    public function index(Request $request): JsonResponse
    {
        // Document state as aggregates on the row, not a relation. The
        // in-transit queue's "documents sent?" column is the whole reason that
        // queue is worth having, and without these it would be one request per
        // row — or a column asserting something it has not been told.
        $query = Order::query()
            ->withCount([
                'tradeDocuments as documents_count',
                'tradeDocuments as documents_sent_count' => fn ($q) => $q->whereNotNull('sent_at'),
            ])
            ->addSelect(['orders.*'])
            ->selectSub(
                TradeDocument::selectRaw('MAX(sent_at)')->whereColumn('order_id', 'orders.id'),
                'last_document_sent_at'
            )
            ;

        // A browsable index is read newest-first; a work queue is worked from
        // the back, because the row that has waited longest is the one someone
        // is chasing. The order was hardcoded, so the fulfilment queue could
        // only be shown in the least useful direction — frontend found this by
        // writing the control and discovering the parameter did nothing.
        //
        // `oldest` sorts by when the order was RAISED, not by how long it has
        // been in its current state. Nothing records the latter, and a proxy
        // dressed up as the real thing is worse than the plain fact.
        $this->applySort($query, (string) $request->input('sort', 'newest'));

        // eBay orders are a separate book: different fulfilment, different
        // paperwork, and the finance board reports them on their own line. The
        // default stays 'all' deliberately — silently hiding rows from every
        // existing consumer of this endpoint (the admin list, the ops mobile
        // app) to achieve a split that belongs in the UI would be a data change
        // dressed up as a feature. The admin panel passes channel=normal on the
        // Orders page and channel=ebay on the eBay page.
        if ($request->filled('channel') && $request->input('channel') !== 'all') {
            $query->channel($request->input('channel'));
        }

        if ($request->filled('in_transit') && $request->boolean('in_transit')) {
            $query->inTransit();
        }

        // Either half of the fulfilment window on its own — the order manager's
        // two jobs are different, and one list containing both is worked in the
        // wrong order.
        if ($request->filled('fulfilment_stage')) {
            $query->fulfilmentStage($request->input('fulfilment_stage'));
        }

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
                'channel'      => $request->input('channel', 'all'),
                'sort'         => $this->sortKey((string) $request->input('sort', 'newest')),
                'sorts'        => self::SORTS,
                // Always present, and always counted across ALL orders rather
                // than the current filter — so the Orders page can say "42 eBay
                // orders, view separately" instead of the split being something
                // the user has to already know about.
                'channel_counts' => [
                    'normal' => Order::query()->channel(Order::CHANNEL_NORMAL)->count(),
                    'ebay'   => Order::query()->channel(Order::CHANNEL_EBAY)->count(),
                ],
            ],
            'message' => 'success',
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['items', 'logs', 'shipmentEvents', 'euDeclaration', 'tradeDocuments'])->findOrFail($id);

        return response()->json([
            'data'    => $this->formatOrderDetail($order, $request->user()),
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
        //
        // This fires only on an explicit payment_status of 'paid', which now
        // means what it says: the money is already in. A live order still
        // awaiting payment is created 'pending' and confirmed later through
        // POST /admin/orders/{id}/mark-paid, which works for manual orders as
        // of Session 76 — before that it 422'd and this form was the only way
        // to reach 'paid' at all.
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
                // No items means no line total to derive from, so the
                // hand-typed total stands in for the subtotal. Keep the two
                // equal: Order::recalculateTotalsFromItems reads the gap
                // between them as delivery/tax/discount and carries it over
                // when the order is later itemised. Setting subtotal to 0
                // here would make that gap the whole order value and the
                // first item added would be charged on top of it.
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

        // An order that is born paid needs a record of who said so.
        //
        // 'paid' at creation is a person asserting the money is already in the
        // bank — right for a paper backlog, and wrong for a live order, which
        // is how the order manager ended up with a buyer looking at a payment
        // he had not made. Every other route to a paid state leaves a row
        // naming whoever confirmed it (`markPaid` writes payment_status_changed,
        // the milestone actions stamp deposit_confirmed_by / balance_confirmed_by).
        // This one wrote nothing, so the assertion could never afterwards be
        // told apart from a derivation — and `orders:payment-state --audit`
        // exists precisely because the ones already on production cannot be.
        //
        // Does not block anything: the backfill workflow is unchanged and the
        // form still supports it. It just stops being anonymous.
        if ($data['payment_status'] === 'paid') {
            $this->writeLog($request, $order, 'payment_status_changed', [
                'old_value' => 'pending',
                'new_value' => 'paid',
                'notes'     => "Recorded as already paid at creation, stage '{$paymentStage}'. "
                    . 'Declared by the admin recording the order, not observed by a gateway.',
            ]);
        }

        $order->load(['items', 'logs', 'euDeclaration', 'tradeDocuments']);

        return response()->json([
            'data'    => $this->formatOrderDetail($order, $request->user()),
            'message' => 'Order recorded successfully.',
        ], 201);
    }

    public function update(Request $request, int $id, CurrencyConversionService $fx): JsonResponse
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
            'currency'           => ['sometimes', Rule::in(['EUR', 'USD'])],
        ]);

        $order          = Order::findOrFail($id);
        $previousStatus = $order->status;

        if ($request->input('status') === 'cancelled' && in_array($order->status, ['cancelled', 'delivered'], true)) {
            return response()->json([
                'message' => 'Order cannot be cancelled in its current state.',
            ], 409);
        }

        if ($request->filled('currency') && ($error = $this->convertOrderCurrency($request, $order, $request->input('currency'), $fx))) {
            return $error;
        }

        $order->update($request->only(['status', 'carrier', 'carrier_type', 'tracking_number', 'container_number', 'estimated_delivery', 'eta', 'admin_notes', 'currency']));
        $order->load(['items', 'logs', 'euDeclaration', 'tradeDocuments']);

        $this->logStatusChange($request, $order, $previousStatus);
        $this->logTrackingChange($request, $order);
        $this->notifyShipmentStatus($order, $previousStatus);
        $this->sendReviewInviteOnDelivery($order, $previousStatus);

        return response()->json([
            'data'    => $this->formatOrderDetail($order, $request->user()),
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
    public function updateStatus(Request $request, int $id, CurrencyConversionService $fx): JsonResponse
    {
        $request->validate([
            'status'             => ['required', Rule::in(['pending', 'confirmed', 'awaiting_proforma', 'processing', 'shipped', 'delivered', 'cancelled'])],
            'carrier'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'carrier_type'       => ['sometimes', 'nullable', Rule::in(['sea', 'air', 'dhl', 'road', 'truck'])],
            'tracking_number'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'container_number'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'estimated_delivery' => ['sometimes', 'nullable', 'date'],
            'eta'                => ['sometimes', 'nullable', 'date'],
            'currency'           => ['sometimes', Rule::in(['EUR', 'USD'])],
        ]);

        $order          = Order::findOrFail($id);
        $previousStatus = $order->status;

        if ($request->input('status') === 'cancelled' && in_array($order->status, ['cancelled', 'delivered'], true)) {
            return response()->json([
                'message' => 'Order cannot be cancelled in its current state.',
            ], 409);
        }

        if ($request->filled('currency') && ($error = $this->convertOrderCurrency($request, $order, $request->input('currency'), $fx))) {
            return $error;
        }

        $order->update($request->only(['status', 'carrier', 'carrier_type', 'tracking_number', 'container_number', 'estimated_delivery', 'eta', 'currency']));

        $this->logStatusChange($request, $order, $previousStatus);
        $this->logTrackingChange($request, $order);
        $this->notifyShipmentStatus($order, $previousStatus);
        $this->sendReviewInviteOnDelivery($order, $previousStatus);

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
                'currency'           => $order->currency ?? 'EUR',
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

        // Only a gateway-owned payment is off-limits: Stripe decides when a
        // Stripe order is paid, and the webhook writes it. Everything else —
        // bank transfer, an admin-recorded order (payment_method is NULL on
        // those), an import — is settled off-platform, so a human confirming
        // receipt is the ONLY thing that can mark it paid.
        //
        // This used to demand payment_method === 'bank_transfer', which no
        // admin-created order has. The endpoint 422'd on every one of them,
        // leaving "tick paid on the creation form" as the only route to a
        // paid order — i.e. declaring payment received before it was. That is
        // the order manager's report: the order marked itself paid.
        if ($order->payment_method === 'stripe') {
            return response()->json([
                'message' => 'This order is paid through Stripe. Its payment status is set by the gateway, not by hand.',
                'code'    => 'gateway_managed_payment',
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
            'notes'     => implode(' | ', $noteParts) ?: 'Payment receipt confirmed by admin.',
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

        // A signature covers a figure. Moving the total after it was signed
        // means the confirmation that would go out is not the one anybody
        // approved, so both signatures come off and have to be given again.
        $withdrawn = 0;

        if (round($newTotal, 2) !== round($oldTotal, 2)) {
            $withdrawn = app(OrderSignoffService::class)->invalidateForFinancialChange(
                $order,
                $request->user(),
                "order total changed from {$oldTotal} to {$newTotal}",
                $request->ip()
            );
        }

        return response()->json([
            'data' => [
                'id'               => $order->id,
                'order_ref'        => $order->ref,
                'delivery_cost'    => (float) $order->delivery_cost,
                'total'            => (float) $order->total,
                'old_delivery_cost' => $oldDeliveryCost,
                'old_total'         => $oldTotal,
                'signoffs_withdrawn' => $withdrawn,
            ],
            'message' => $withdrawn > 0
                ? 'Order financials updated. The total changed, so the order confirmation sign-offs were withdrawn and must be given again.'
                : 'Order financials updated successfully.',
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

    /**
     * Converts every money figure on the order (and its line items) to
     * $newCurrency at today's real exchange rate — this is a genuine
     * conversion, not a display relabel, at the customer's explicit
     * request. Returns null on success (order + items already saved with
     * converted figures); returns a JsonResponse to send back immediately
     * if the conversion is blocked or the rate lookup fails. Does nothing
     * (returns null) if $newCurrency matches the order's current currency.
     *
     * tax_rate and deposit_percent are ratios, not money — never converted.
     */
    private function convertOrderCurrency(Request $request, Order $order, string $newCurrency, CurrencyConversionService $fx): ?JsonResponse
    {
        $fromCurrency = $order->currency ?? 'EUR';
        if (strtoupper($newCurrency) === strtoupper($fromCurrency)) {
            return null;
        }

        if ($order->source === 'ebay') {
            return response()->json([
                'message' => 'This order is synced from eBay — currency is managed there. Correct it in eBay instead.',
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

        try {
            $rateInfo = $fx->getRate($fromCurrency, $newCurrency);
        } catch (\Throwable $e) {
            Log::error('[order_currency_conversion_rate_failed] Could not fetch exchange rate', [
                'order_ref'     => $order->ref,
                'from_currency' => $fromCurrency,
                'to_currency'   => $newCurrency,
                'error'         => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "Could not fetch today's {$fromCurrency}/{$newCurrency} exchange rate. Please try again shortly.",
                'code'    => 'exchange_rate_unavailable',
            ], 502);
        }

        $rate        = $rateInfo['rate'];
        $moneyFields = ['subtotal', 'delivery_cost', 'discount_amount', 'total', 'tax_amount', 'deposit_amount', 'balance_amount'];
        $before      = ['order' => [], 'items' => []];
        $after       = ['order' => [], 'items' => []];

        DB::transaction(function () use ($order, $moneyFields, $rate, $newCurrency, &$before, &$after) {
            foreach ($moneyFields as $field) {
                if ($order->{$field} === null) {
                    continue;
                }
                $before['order'][$field] = (float) $order->{$field};
                $order->{$field}         = round(((float) $order->{$field}) * $rate, 2);
                $after['order'][$field]  = (float) $order->{$field};
            }
            $order->currency = $newCurrency;
            $order->save();

            foreach (OrderItem::where('order_id', $order->id)->get() as $item) {
                $before['items'][] = ['id' => $item->id, 'unit_price' => (float) $item->unit_price, 'line_total' => (float) $item->line_total];
                $item->unit_price  = round(((float) $item->unit_price) * $rate, 2);
                $item->line_total  = round(((float) $item->line_total) * $rate, 2);
                $item->save();
                $after['items'][] = ['id' => $item->id, 'unit_price' => (float) $item->unit_price, 'line_total' => (float) $item->line_total];
            }
        });

        $this->writeLog($request, $order, 'currency_converted', [
            'old_value' => $fromCurrency,
            'new_value' => $newCurrency,
            'notes'     => json_encode([
                'rate'      => $rate,
                'rate_date' => $rateInfo['date'],
                'source'    => 'frankfurter.app (ECB)',
                'before'    => $before,
                'after'     => $after,
            ]),
        ]);

        return null;
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

    /** Falls back to the default rather than erroring on an unknown value. */
    private function sortKey(string $sort): string
    {
        return array_key_exists($sort, self::SORTS) ? $sort : 'newest';
    }

    private function applySort($query, string $sort): void
    {
        match ($this->sortKey($sort)) {
            'oldest'     => $query->orderBy('created_at'),
            'total_high' => $query->orderByDesc('total')->orderByDesc('created_at'),
            'total_low'  => $query->orderBy('total')->orderByDesc('created_at'),
            'updated'    => $query->orderByDesc('updated_at'),
            default      => $query->orderByDesc('created_at'),
        };
    }

    private function formatOrderList(Order $o): array
    {
        return [
            'id'             => $o->id,
            'order_ref'      => $o->ref,
            'source'         => $o->source ?? 'website',
            // Derived from source, never stored — a second column saying the
            // same thing is a column that can disagree with it.
            'channel'        => $o->channel(),
            'in_transit'     => $o->isInTransit(),
            'fulfilment_stage' => $o->fulfilmentStage(),
            // Enough to answer "has the paperwork gone out?" without opening
            // the order. Null rather than 0 when the aggregate was not
            // selected, so a caller can tell "none sent" from "not asked".
            'documents_count'       => $o->documents_count === null ? null : (int) $o->documents_count,
            'documents_sent_count'  => $o->documents_sent_count === null ? null : (int) $o->documents_sent_count,
            'last_document_sent_at' => $o->last_document_sent_at
                ? \Carbon\Carbon::parse($o->last_document_sent_at)->toIso8601String()
                : null,
            'customer_name'  => $o->customer_name,
            'customer_email' => $o->customer_email,
            'total'          => (float) $o->total,
            'currency'       => $o->currency ?? 'EUR',
            'status'         => $o->status,
            'payment_status' => $o->payment_status,
            'payment_method' => $o->payment_method,
            'created_at'     => $o->created_at?->toIso8601String(),
        ];
    }

    private function formatOrderDetail(Order $o, ?\App\Models\AdminUser $viewer = null): array
    {
        return [
            'id'                 => $o->id,
            'order_ref'          => $o->ref,
            'customer_name'      => $o->customer_name,
            'customer_email'     => $o->customer_email,
            'phone'              => $o->customer_phone,
            'source'             => $o->source ?? 'website',
            'channel'            => $o->channel(),
            'in_transit'         => $o->isInTransit(),
            'fulfilment_stage'   => $o->fulfilmentStage(),
            // The two signatures on the order confirmation. Always present, so
            // the panel never has to make a second request to find out whether
            // there is anything to show.
            // The viewer travels with it so `you_may_sign` / `you_may_revoke`
            // are answered here — frontend was otherwise making a second
            // request to /signoffs for the one question of which button to
            // offer, which is the thing embedding this block was meant to save.
            'signoff'            => app(\App\Services\OrderSignoffService::class)->state($o, $viewer),
            // Whether the finalized revenue invoice exists and what the order
            // made — the ask was that order tracking KNOWS, so it travels on
            // the order rather than behind a second request. Null until the
            // Session 99 migration has run; the order page must never fail
            // because a reporting feature arrived before its tables.
            'finance'            => app(\App\Services\OrderProfitabilityService::class)->summaryForOrder($o),
            'company_name'       => null,
            'address'            => trim(implode(', ', array_filter([$o->address, $o->city, $o->postal_code]))),
            'country'            => $o->country,
            'total'              => (float) $o->total,
            'currency'           => $o->currency ?? 'EUR',
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
            'payment_milestones_active'            => $o->paymentMilestonesActive(),
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

    /**
     * One review invite, on the transition into delivered, once per order
     * ever (Session 118).
     *
     * Silent until the business sets REVIEW_INVITE_URL — the review profile
     * has to exist before we point customers at it. Never fails the status
     * change: a delivered order stays delivered whether or not the invite
     * could be written or sent, and the column guard also covers production
     * running ahead of migration #65.
     */
    private function sendReviewInviteOnDelivery(Order $order, ?string $previousStatus): void
    {
        try {
            if ($order->status !== 'delivered' || $previousStatus === 'delivered') {
                return;
            }
            if (! config('reviews.enabled') || ! config('reviews.url')) {
                return;
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn('orders', 'review_invite_sent_at')) {
                return;
            }
            if ($order->review_invite_sent_at !== null) {
                return;
            }

            $email = $order->customer_email;
            if (! $email) {
                return;
            }

            \Illuminate\Support\Facades\Mail::to($email)
                ->send(new \App\Mail\ReviewInviteEmail($order, config('reviews.url')));

            $order->forceFill(['review_invite_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Review invite failed', [
                'order' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
