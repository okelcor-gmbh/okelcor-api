<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EuDeclaration;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLogisticsController extends Controller
{
    // Payment stages that require logistics attention
    private const LOGISTICS_STAGES = [
        'deposit_paid',
        'balance_due',
        'balance_paid',
        'shipment_released',
    ];

    // Doc-generation stages: commercial invoice + packing list can be generated
    private const DOC_STAGES = [
        'deposit_paid',
        'balance_due',
        'balance_paid',
        'shipment_released',
    ];

    public function dashboard(Request $request): JsonResponse
    {
        $query = Order::with([
                'tradeDocuments' => fn ($q) => $q->where('status', 'issued'),
                'euDeclaration',
            ])
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('updated_at');

        $this->applyFilters($query, $request);

        $perPage   = min((int) $request->input('per_page', 20), 100);
        $paginated = $query->paginate($perPage);

        $checklist = $paginated->map(fn ($order) => $this->buildChecklist($order));

        return response()->json([
            'data' => [
                'summary'   => $this->buildSummary(),
                'checklist' => $checklist,
            ],
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // Summary cards
    // -------------------------------------------------------------------------

    private function buildSummary(): array
    {
        $base = fn () => Order::whereNotIn('status', ['cancelled']);

        // eBay base — orders from eBay that are paid and not yet fulfilled
        $ebayBase = fn () => Order::where('source', 'ebay')
            ->whereNotIn('status', ['cancelled']);

        return [
            // Operational totals
            'total_active_orders'           => $base()->count(),
            'total_ebay_orders'             => $ebayBase()->count(),

            // Pre-logistics (finance stages)
            'awaiting_proforma'             => $base()
                ->where(fn ($q) => $q
                    ->whereNull('payment_stage')
                    ->orWhere('payment_stage', 'pending_proforma')
                )
                ->whereNotIn('status', ['pending'])
                ->count(),

            'awaiting_customer_acceptance'  => $base()
                ->where('customer_acceptance_status', 'pending')
                ->where(fn ($q) => $q
                    ->whereNotNull('payment_stage')
                    ->where('payment_stage', '!=', 'pending_proforma')
                )
                ->count(),

            'awaiting_deposit'              => $base()
                ->where('payment_stage', 'deposit_requested')
                ->count(),

            // Active logistics stages
            'deposit_paid_docs_needed'      => $base()
                ->where('payment_stage', 'deposit_paid')
                ->where(fn ($q) => $q
                    ->whereDoesntHave('tradeDocuments', fn ($d) => $d->where('type', 'commercial_invoice')->where('status', 'issued'))
                    ->orWhereDoesntHave('tradeDocuments', fn ($d) => $d->where('type', 'packing_list')->where('status', 'issued'))
                )
                ->count(),

            'balance_due'                   => $base()
                ->where('payment_stage', 'balance_due')
                ->count(),

            'ready_for_shipment_release'    => $base()
                ->where('payment_stage', 'balance_paid')
                ->count(),

            'shipment_released'             => $base()
                ->where('payment_stage', 'shipment_released')
                ->count(),

            // eBay-specific
            'ebay_needing_fulfillment'      => $ebayBase()
                ->where('payment_status', 'paid')
                ->whereNotIn('ebay_fulfillment_status', ['FULFILLED', 'CANCELLED'])
                ->count(),

            // Document gaps
            'missing_commercial_invoice'    => $base()
                ->whereIn('payment_stage', self::DOC_STAGES)
                ->whereDoesntHave('tradeDocuments', fn ($q) => $q->where('type', 'commercial_invoice')->where('status', 'issued'))
                ->count(),

            'missing_packing_list'          => $base()
                ->whereIn('payment_stage', self::DOC_STAGES)
                ->whereDoesntHave('tradeDocuments', fn ($q) => $q->where('type', 'packing_list')->where('status', 'issued'))
                ->count(),

            'missing_shipment_document'     => $base()
                ->where('payment_stage', 'shipment_released')
                ->whereDoesntHave('tradeDocuments', fn ($q) => $q->where('type', 'shipment_document')->where('status', 'issued'))
                ->count(),

            // EU compliance
            'pending_eu_declarations'       => $base()
                ->where('is_reverse_charge', true)
                ->whereHas('euDeclaration', fn ($q) => $q->whereNotNull('signed_at')->whereNull('admin_acknowledged_at'))
                ->count(),

            'high_risk_orders'              => $base()
                ->where('is_reverse_charge', true)
                ->where('status', 'delivered')
                ->whereDoesntHave('euDeclaration', fn ($q) => $q->whereNotNull('admin_acknowledged_at'))
                ->count(),

            // Legacy / fallback counts (pre-milestone orders)
            'orders_shipped'                => $base()->whereIn('status', ['shipped', 'delivered'])->count(),
            'orders_delivered'              => $base()->where('status', 'delivered')->count(),
        ];
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    private function applyFilters($query, Request $request): void
    {
        // Source filter: all / website / ebay
        if ($request->filled('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('payment_stage')) {
            $query->where('payment_stage', $request->payment_stage);
        }

        if ($request->filled('ebay_fulfillment_status')) {
            $query->where('ebay_fulfillment_status', $request->ebay_fulfillment_status);
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

        // missing_document filter — uses payment_stage-aware conditions
        if ($request->filled('missing_document')) {
            $type = $request->missing_document;
            $query->whereDoesntHave(
                'tradeDocuments',
                fn ($q) => $q->where('type', $type)->where('status', 'issued')
            );

            match ($type) {
                'packing_list', 'commercial_invoice' =>
                    $query->where(fn ($q) => $q
                        ->whereIn('payment_stage', self::DOC_STAGES)
                        ->orWhere('payment_status', 'paid')  // legacy fallback
                    ),
                'shipment_document' =>
                    $query->where(fn ($q) => $q
                        ->where('payment_stage', 'shipment_released')
                        ->orWhereIn('status', ['shipped', 'delivered'])  // legacy fallback
                    ),
                'delivery_note' =>
                    $query->where(fn ($q) => $q
                        ->where('payment_stage', 'shipment_released')
                        ->orWhere('status', 'delivered')  // legacy fallback
                    ),
                default => null,
            };
        }

        // risk_level=high filter
        if ($request->input('risk_level') === 'high') {
            $query->where('is_reverse_charge', true)
                ->where('status', 'delivered')
                ->whereDoesntHave('euDeclaration', fn ($q) => $q->whereNotNull('admin_acknowledged_at'));
        }

        // logistics_ready_only: restrict to orders that need logistics action
        if ($request->boolean('logistics_ready_only')) {
            $query->where(fn ($q) => $q
                ->whereIn('payment_stage', self::LOGISTICS_STAGES)
                ->orWhereIn('status', ['processing', 'shipped', 'delivered'])
                ->orWhere(fn ($q2) => $q2
                    ->where('source', 'ebay')
                    ->where('payment_status', 'paid')
                    ->whereNotIn('ebay_fulfillment_status', ['FULFILLED', 'CANCELLED'])
                )
                ->orWhere('is_reverse_charge', true)
            );
        }
    }

    // -------------------------------------------------------------------------
    // Per-order checklist row
    // -------------------------------------------------------------------------

    private function buildChecklist(Order $order): array
    {
        $docs    = $this->checkDocuments($order);
        $missing = $this->computeMissing($order, $docs);
        $decl    = $order->euDeclaration;
        $stage   = $this->resolveStage($order);

        return [
            // Identity
            'order_id'                     => $order->id,
            'order_ref'                    => $order->ref,
            'customer_name'                => $order->customer_name,
            'customer_email'               => $order->customer_email,
            'country'                      => $order->country,

            // Source
            'source'                       => $order->source ?? 'website',
            'ebay_order_id'                => $order->ebay_order_id,
            'ebay_payment_status'          => $order->ebay_payment_status,
            'ebay_fulfillment_status'      => $order->ebay_fulfillment_status,

            // Workflow state
            'status'                       => $order->status,
            'payment_status'               => $order->payment_status,
            'payment_stage'                => $stage,
            'customer_acceptance_status'   => $order->customer_acceptance_status ?? 'pending',
            'financials_locked'            => $order->isFinancialsLocked(),
            'financials_revision_required' => (bool) $order->financials_revision_required,
            'is_reverse_charge'            => (bool) $order->is_reverse_charge,

            // Financials
            'total'                        => (float) $order->total,
            'deposit_amount'               => $order->deposit_amount !== null ? (float) $order->deposit_amount : null,
            'balance_amount'               => $order->balance_amount !== null ? (float) $order->balance_amount : null,

            // Timestamps
            'created_at'                   => $order->created_at?->toIso8601String(),
            'updated_at'                   => $order->updated_at?->toIso8601String(),
            'deposit_paid_at'              => $order->deposit_paid_at?->toIso8601String(),
            'balance_paid_at'              => $order->balance_paid_at?->toIso8601String(),
            'shipment_released_at'         => $order->shipment_released_at?->toIso8601String(),

            // Document checklist
            'documents'                    => $docs,
            'missing'                      => $missing,

            // EU compliance
            'eu_declaration'               => $decl ? [
                'id'                    => $decl->id,
                'status'                => $decl->status,
                'signed_at'             => $decl->signed_at?->toIso8601String(),
                'admin_acknowledged_at' => $decl->admin_acknowledged_at?->toIso8601String(),
            ] : null,

            // Action guidance
            'risk_level'                   => $this->computeRiskLevel($order, $missing, $decl, $stage),
            'next_action'                  => $this->computeNextAction($order, $missing, $decl, $stage),
        ];
    }

    // -------------------------------------------------------------------------
    // Document checks
    // -------------------------------------------------------------------------

    private function checkDocuments(Order $order): array
    {
        $byType = $order->tradeDocuments->groupBy('type');

        return [
            'order_confirmation' => $byType->has('order_confirmation'),
            'proforma'           => $byType->has('proforma'),
            'commercial_invoice' => $byType->has('commercial_invoice'),
            'packing_list'       => $byType->has('packing_list'),
            'delivery_note'      => $byType->has('delivery_note'),
            'shipment_document'  => $byType->has('shipment_document'),
        ];
    }

    /**
     * Compute which documents are still missing based on payment_stage (primary)
     * or payment_status/order status (legacy fallback for pre-milestone orders).
     */
    private function computeMissing(Order $order, array $docs): array
    {
        $missing = [];
        $stage   = $this->resolveStage($order);

        // Determine if this order is at a stage where trade docs should exist
        $docsExpected = in_array($stage, self::DOC_STAGES, true)
            || $order->payment_status === 'paid'   // legacy
            || ($order->source === 'ebay' && $order->payment_status === 'paid');

        if ($docsExpected) {
            if (! $docs['commercial_invoice']) {
                $missing[] = 'commercial_invoice';
            }
            if (! $docs['packing_list']) {
                $missing[] = 'packing_list';
            }
        }

        // Shipment document: needed once shipment is released or order is shipped/delivered
        $shipmentExpected = $stage === 'shipment_released'
            || in_array($order->status, ['shipped', 'delivered'], true);

        if ($shipmentExpected && ! $docs['shipment_document']) {
            $missing[] = 'shipment_document';
        }

        // Delivery note: needed once shipment is released or delivered
        $deliveryExpected = $stage === 'shipment_released'
            || $order->status === 'delivered';

        if ($deliveryExpected && ! $docs['delivery_note']) {
            $missing[] = 'delivery_note';
        }

        return $missing;
    }

    // -------------------------------------------------------------------------
    // Risk level
    // -------------------------------------------------------------------------

    private function computeRiskLevel(
        Order $order,
        array $missing,
        ?EuDeclaration $decl,
        string $stage
    ): string {
        // High: reverse charge, delivered, declaration not acknowledged
        if ($order->is_reverse_charge && $order->status === 'delivered') {
            if (! $decl || $decl->admin_acknowledged_at === null) {
                return 'high';
            }
        }

        // High: eBay order, payment disputed/failed
        if ($order->source === 'ebay' && in_array($order->payment_status, ['failed', 'refunded'], true)) {
            return 'high';
        }

        // Medium: declaration signed but not yet acknowledged by admin
        if ($decl && $decl->signed_at !== null && $decl->admin_acknowledged_at === null) {
            return 'medium';
        }

        // Medium: balance fully paid but shipment not yet released (finance action overdue)
        if ($stage === 'balance_paid') {
            return 'medium';
        }

        // Medium: missing shipment docs on a shipped/delivered order
        if (in_array('shipment_document', $missing, true) || in_array('delivery_note', $missing, true)) {
            return 'medium';
        }

        // Low: missing trade docs when docs are expected
        if (in_array('commercial_invoice', $missing, true) || in_array('packing_list', $missing, true)) {
            return 'low';
        }

        return 'none';
    }

    // -------------------------------------------------------------------------
    // Next-action guidance — payment_stage-first, then status fallback
    // -------------------------------------------------------------------------

    private function computeNextAction(
        Order $order,
        array $missing,
        ?EuDeclaration $decl,
        string $stage
    ): string {
        // ── EU compliance — highest priority for reverse-charge delivered orders ──
        if ($order->is_reverse_charge && $order->status === 'delivered') {
            if (! $decl) {
                return 'Request EU entry certificate from customer';
            }
            if ($decl->signed_at === null) {
                return 'Awaiting customer signature on EU declaration';
            }
            if ($decl->admin_acknowledged_at === null) {
                return 'Acknowledge signed EU declaration';
            }
        }

        // ── Customer acceptance gate ──────────────────────────────────────────
        if (($order->customer_acceptance_status ?? 'pending') === 'pending'
            && ! in_array($stage, ['pending_proforma'], true)
        ) {
            return 'Awaiting customer acceptance of order confirmation';
        }

        if (($order->customer_acceptance_status ?? 'pending') === 'rejected') {
            return 'Customer rejected order confirmation — review and re-send';
        }

        // ── Payment milestone workflow ─────────────────────────────────────────
        switch ($stage) {
            case 'pending_proforma':
                return 'Finance: generate proforma invoice to initiate payment';

            case 'deposit_requested':
                return 'Awaiting deposit payment from customer';

            case 'deposit_paid':
                if (in_array('commercial_invoice', $missing, true) || in_array('packing_list', $missing, true)) {
                    $needed = implode(' + ', array_map(
                        fn ($d) => match ($d) {
                            'commercial_invoice' => 'commercial invoice',
                            'packing_list'       => 'packing list',
                            default              => $d,
                        },
                        array_intersect($missing, ['commercial_invoice', 'packing_list'])
                    ));
                    return "Generate {$needed}";
                }
                return 'Deposit paid — documents ready, awaiting balance invoice';

            case 'balance_due':
                return 'Awaiting balance payment from customer';

            case 'balance_paid':
                return 'Full payment received — finance to release shipment';

            case 'shipment_released':
                if (in_array('shipment_document', $missing, true)) {
                    return 'Upload shipment document (bill of lading / AWB)';
                }
                if (in_array('delivery_note', $missing, true)) {
                    return 'Generate delivery note';
                }
                if (! $order->tracking_number && ! $order->container_number) {
                    return 'Add tracking number / container number';
                }
                return 'Shipment released — monitor delivery progress';
        }

        // ── eBay-specific (no milestone set) ──────────────────────────────────
        if ($order->source === 'ebay') {
            if ($order->payment_status === 'paid') {
                $fulfillment = strtoupper($order->ebay_fulfillment_status ?? '');
                if (! in_array($fulfillment, ['FULFILLED', 'CANCELLED'], true)) {
                    return 'Prepare and ship eBay order — mark fulfilled on eBay';
                }
                if ($fulfillment === 'FULFILLED' && in_array('shipment_document', $missing, true)) {
                    return 'Upload shipment document for shipped eBay order';
                }
            }
            if (in_array($order->payment_status, ['failed', 'refunded'], true)) {
                return 'eBay payment issue — review order on eBay Seller Hub';
            }
        }

        // ── Legacy fallback (pre-milestone orders, status-based) ──────────────
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

        if ($order->status === 'shipped') {
            return 'In transit — awaiting delivery confirmation';
        }

        if ($order->payment_status === 'paid') {
            return 'Paid — prepare shipment documents';
        }

        return 'No immediate action required';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve effective payment_stage for an order.
     * If payment_stage is null (pre-milestone order), infer from payment_status/status.
     */
    private function resolveStage(Order $order): string
    {
        if (! empty($order->payment_stage)) {
            return $order->payment_stage;
        }

        // Infer for legacy orders
        if ($order->payment_status === 'paid') {
            if (in_array($order->status, ['shipped', 'delivered'], true)) {
                return 'shipment_released';
            }
            return 'balance_paid';
        }

        if ($order->status === 'processing') {
            return 'deposit_paid';
        }

        return 'pending_proforma';
    }
}
