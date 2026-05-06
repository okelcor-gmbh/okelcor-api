<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLog;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
        $order = Order::with(['items', 'logs', 'shipmentEvents'])->findOrFail($id);

        return response()->json([
            'data'    => $this->formatOrderDetail($order),
            'message' => 'success',
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'             => ['required', Rule::in(['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'])],
            'carrier'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'carrier_type'       => ['sometimes', 'nullable', Rule::in(['sea', 'air', 'dhl', 'road', 'bus'])],
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

        $order->update($request->only(['status', 'carrier', 'tracking_number', 'container_number', 'estimated_delivery', 'eta', 'admin_notes']));
        $order->load(['items', 'logs']);

        $this->logStatusChange($request, $order, $previousStatus);
        $this->logTrackingChange($request, $order);

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
            'status'             => ['required', Rule::in(['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'])],
            'carrier'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'carrier_type'       => ['sometimes', 'nullable', Rule::in(['sea', 'air', 'dhl', 'road', 'bus'])],
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

        $order->update($request->only(['status', 'carrier', 'tracking_number', 'container_number', 'estimated_delivery', 'eta']));

        $this->logStatusChange($request, $order, $previousStatus);
        $this->logTrackingChange($request, $order);

        return response()->json([
            'data'    => [
                'id'                 => $order->id,
                'ref'                => $order->ref,
                'status'             => $order->status,
                'carrier'            => $order->carrier,
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

        // Invoice — idempotent; won't duplicate if one already exists
        $invoice = app(InvoiceService::class)->createForOrder($fresh);

        // Customer confirmation email
        try {
            Mail::to($fresh->customer_email)->send(new OrderConfirmation($fresh, $invoice));
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

    private function logTrackingChange(Request $request, Order $order): void
    {
        if (! $order->wasChanged(['carrier', 'tracking_number', 'container_number', 'estimated_delivery', 'eta'])) {
            return;
        }

        $this->writeLog($request, $order, 'tracking_updated', [
            'notes' => 'Tracking fields updated.',
        ]);
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
        ];
    }
}
