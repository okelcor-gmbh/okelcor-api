<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EuDeclaration;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLogisticsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $query = Order::with([
                'tradeDocuments' => fn ($q) => $q->where('status', 'issued'),
                'euDeclaration',
            ])
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('created_at');

        $this->applyFilters($query, $request);

        $perPage   = min((int) $request->input('per_page', 20), 100);
        $paginated = $query->paginate($perPage);

        $orderRefs     = $paginated->pluck('ref')->all();
        $invoicesByRef = Invoice::whereIn('order_ref', $orderRefs)->get()->keyBy('order_ref');

        $checklist = $paginated->map(
            fn ($order) => $this->buildChecklist($order, $invoicesByRef->get($order->ref))
        );

        return response()->json([
            'data' => [
                'summary'   => $this->buildSummary(),
                'checklist' => $checklist,
            ],
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildSummary(): array
    {
        $base = fn () => Order::whereNotIn('status', ['cancelled']);

        return [
            'total_active_orders'        => $base()->count(),
            'awaiting_payment'           => $base()->where('payment_status', 'unpaid')->count(),
            'paid_orders'                => $base()->where('payment_status', 'paid')->count(),
            'orders_shipped'             => $base()->whereIn('status', ['shipped', 'delivered'])->count(),
            'orders_delivered'           => $base()->where('status', 'delivered')->count(),
            'missing_packing_list'       => $base()->where('payment_status', 'paid')
                ->whereDoesntHave('tradeDocuments', fn ($q) => $q->where('type', 'packing_list')->where('status', 'issued'))
                ->count(),
            'missing_commercial_invoice' => $base()->where('payment_status', 'paid')
                ->whereDoesntHave('tradeDocuments', fn ($q) => $q->where('type', 'commercial_invoice')->where('status', 'issued'))
                ->count(),
            'missing_shipment_document'  => $base()->whereIn('status', ['shipped', 'delivered'])
                ->whereDoesntHave('tradeDocuments', fn ($q) => $q->where('type', 'shipment_document')->where('status', 'issued'))
                ->count(),
            'pending_eu_declarations'    => $base()->where('is_reverse_charge', true)
                ->whereHas('euDeclaration', fn ($q) => $q->whereNotNull('signed_at')->whereNull('admin_acknowledged_at'))
                ->count(),
            'high_risk_orders'           => $base()->where('is_reverse_charge', true)
                ->where('status', 'delivered')
                ->whereDoesntHave('euDeclaration', fn ($q) => $q->whereNotNull('admin_acknowledged_at'))
                ->count(),
        ];
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }

        if ($request->boolean('reverse_charge_only')) {
            $query->where('is_reverse_charge', true);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('missing_document')) {
            $type = $request->missing_document;
            $query->whereDoesntHave(
                'tradeDocuments',
                fn ($q) => $q->where('type', $type)->where('status', 'issued')
            );
            match ($type) {
                'packing_list', 'commercial_invoice'
                    => $query->where('payment_status', 'paid'),
                'shipment_document', 'delivery_note'
                    => $query->whereIn('status', ['shipped', 'delivered']),
                default => null,
            };
        }

        // risk_level=high is DB-filterable; medium/low are applied post-query
        if ($request->input('risk_level') === 'high') {
            $query->where('is_reverse_charge', true)
                ->where('status', 'delivered')
                ->whereDoesntHave('euDeclaration', fn ($q) => $q->whereNotNull('admin_acknowledged_at'));
        }
    }

    private function buildChecklist(Order $order, ?Invoice $invoice): array
    {
        $docs          = $this->checkDocuments($order);
        $missing       = $this->computeMissing($order, $docs);
        $decl          = $order->euDeclaration;
        $invoiceLocked = $invoice !== null && $invoice->released_at !== null;

        return [
            'order_id'          => $order->id,
            'order_ref'         => $order->ref,
            'customer_name'     => $order->customer_name,
            'customer_email'    => $order->customer_email,
            'country'           => $order->country,
            'status'            => $order->status,
            'payment_status'    => $order->payment_status,
            'is_reverse_charge' => $order->is_reverse_charge,
            'total'             => $order->total,
            'created_at'        => $order->created_at?->toIso8601String(),
            'documents'         => $docs,
            'missing'           => $missing,
            'eu_declaration'    => $decl ? [
                'id'                    => $decl->id,
                'status'                => $decl->status,
                'signed_at'             => $decl->signed_at?->toIso8601String(),
                'admin_acknowledged_at' => $decl->admin_acknowledged_at?->toIso8601String(),
            ] : null,
            'invoice_number'    => $invoice?->invoice_number,
            'invoice_locked'    => $invoiceLocked,
            'risk_level'        => $this->computeRiskLevel($order, $missing, $decl, $invoiceLocked),
            'next_action'       => $this->computeNextAction($order, $missing, $decl),
        ];
    }

    private function checkDocuments(Order $order): array
    {
        $byType = $order->tradeDocuments->groupBy('type');

        return [
            'proforma'           => $byType->has('proforma'),
            'commercial_invoice' => $byType->has('commercial_invoice'),
            'packing_list'       => $byType->has('packing_list'),
            'delivery_note'      => $byType->has('delivery_note'),
            'shipment_document'  => $byType->has('shipment_document'),
        ];
    }

    private function computeMissing(Order $order, array $docs): array
    {
        $missing = [];

        if ($order->payment_status === 'paid') {
            if (!$docs['packing_list']) {
                $missing[] = 'packing_list';
            }
            if (!$docs['commercial_invoice']) {
                $missing[] = 'commercial_invoice';
            }
        }

        if (in_array($order->status, ['shipped', 'delivered'], true)) {
            if (!$docs['shipment_document']) {
                $missing[] = 'shipment_document';
            }
        }

        if ($order->status === 'delivered') {
            if (!$docs['delivery_note']) {
                $missing[] = 'delivery_note';
            }
        }

        return $missing;
    }

    private function computeRiskLevel(
        Order $order,
        array $missing,
        ?EuDeclaration $decl,
        bool $invoiceLocked
    ): string {
        // High: reverse charge, delivered, declaration not acknowledged
        if ($order->is_reverse_charge && $order->status === 'delivered') {
            if (!$decl || $decl->admin_acknowledged_at === null) {
                return 'high';
            }
        }

        // Medium: declaration signed but admin hasn't acknowledged yet
        if ($decl && $decl->signed_at !== null && $decl->admin_acknowledged_at === null) {
            return 'medium';
        }

        // Medium: missing shipment or delivery docs on a shipped/delivered order
        if (in_array('shipment_document', $missing, true) || in_array('delivery_note', $missing, true)) {
            return 'medium';
        }

        // Low: missing trade docs on a paid order
        if (in_array('packing_list', $missing, true) || in_array('commercial_invoice', $missing, true)) {
            return 'low';
        }

        return 'none';
    }

    private function computeNextAction(Order $order, array $missing, ?EuDeclaration $decl): string
    {
        // EU compliance actions take priority for reverse charge delivered orders
        if ($order->is_reverse_charge && $order->status === 'delivered') {
            if (!$decl) {
                return 'Request EU entry certificate from customer';
            }
            if ($decl->signed_at === null) {
                return 'Awaiting customer signature on EU declaration';
            }
            if ($decl->admin_acknowledged_at === null) {
                return 'Acknowledge signed EU declaration';
            }
        }

        if (in_array('shipment_document', $missing, true)) {
            return 'Upload shipment document (bill of lading / AWB)';
        }

        if (in_array('delivery_note', $missing, true)) {
            return 'Generate delivery note';
        }

        if (in_array('commercial_invoice', $missing, true)) {
            return 'Generate commercial invoice';
        }

        if (in_array('packing_list', $missing, true)) {
            return 'Generate packing list';
        }

        if ($order->status === 'delivered') {
            return 'Order complete — no action required';
        }

        return 'No action required';
    }
}
