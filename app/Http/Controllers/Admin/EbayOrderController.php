<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EbayOrderSyncLog;
use App\Models\Order;
use App\Services\EbayOrderSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EbayOrderController extends Controller
{
    public function __construct(private EbayOrderSyncService $syncService) {}

    /**
     * List eBay-sourced orders with basic sync metadata.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::where('source', 'ebay')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->where('ref', 'like', "%{$q}%")
                    ->orWhere('ebay_order_id', 'like', "%{$q}%")
                    ->orWhere('ebay_buyer_username', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%");
            });
        }

        $perPage   = min((int) $request->input('per_page', 20), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn ($o) => $this->formatEbayOrderRow($o)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    /**
     * Trigger a bulk sync of recent eBay orders.
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $days  = (int) $request->input('days', 30);
        $stats = $this->syncService->syncRecent($days);

        return response()->json([
            'data'    => $stats,
            'message' => "eBay sync complete (last {$days} days).",
        ]);
    }

    /**
     * Sync a single eBay order by its eBay order ID.
     */
    public function syncOne(string $ebayOrderId): JsonResponse
    {
        $stats = $this->syncService->syncOne($ebayOrderId);

        return response()->json([
            'data'    => $stats,
            'message' => "eBay order {$ebayOrderId} sync complete.",
        ]);
    }

    /**
     * List sync log entries for a given eBay order.
     */
    public function logs(Request $request): JsonResponse
    {
        $query = EbayOrderSyncLog::orderByDesc('created_at');

        if ($request->filled('ebay_order_id')) {
            $query->where('ebay_order_id', $request->ebay_order_id);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $perPage   = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn ($log) => [
                'id'              => $log->id,
                'ebay_order_id'   => $log->ebay_order_id,
                'order_id'        => $log->order_id,
                'action'          => $log->action,
                'status'          => $log->status,
                'error_message'   => $log->error_message,
                'payload_summary' => $log->payload_summary,
                'created_at'      => $log->created_at?->toIso8601String(),
            ]),
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

    private function formatEbayOrderRow(Order $o): array
    {
        return [
            'id'                   => $o->id,
            'order_ref'            => $o->ref,
            'ebay_order_id'        => $o->ebay_order_id,
            'ebay_buyer_username'  => $o->ebay_buyer_username,
            'customer_name'        => $o->customer_name,
            'status'               => $o->status,
            'payment_status'       => $o->payment_status,
            'ebay_order_status'    => $o->ebay_order_status,
            'ebay_payment_status'  => $o->ebay_payment_status,
            'total'                => (float) $o->total,
            'ebay_last_synced_at'  => $o->ebay_last_synced_at?->toIso8601String(),
            'created_at'           => $o->created_at?->toIso8601String(),
        ];
    }
}
