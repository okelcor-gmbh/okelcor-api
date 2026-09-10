<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * The finance stock ledger — built from finance's own draft (stock.html):
 * inventory by brand + size + condition grade, supplier invoices booking
 * stock in (merged into matching lines, latest cost kept), customer sale
 * invoices booking stock out (shortage-checked across the whole invoice,
 * quantities locked while deducting), and every invoice kept as the
 * transaction log. Reads under finance.view; writes under finance.manage.
 */
class AdminStockLedgerController extends Controller
{
    // ── GET /api/v1/admin/stock — finance.view ───────────────────────────────
    public function index(): JsonResponse
    {
        if (! Schema::hasTable('stock_items')) {
            return response()->json([
                'message' => 'The stock ledger is not migrated yet — run the migration first.',
            ], 503);
        }

        $items = StockItem::orderBy('brand')->orderBy('size')->get();

        $transactions = StockTransaction::orderByDesc('id')->limit(200)->get()
            ->map(fn (StockTransaction $t) => [
                'id'           => $t->id,
                'type'         => $t->type,
                'party_name'   => $t->party_name,
                'reference'    => $t->reference,
                'total_qty'    => $t->total_qty,
                'total_amount' => (float) $t->total_amount,
                'lines'        => $t->lines,
                'created_at'   => $t->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'items'        => $items->map(fn (StockItem $i) => [
                    'id'    => $i->id,
                    'brand' => $i->brand,
                    'size'  => $i->size,
                    'tread' => $i->tread,
                    'grade' => $i->grade,
                    'qty'   => $i->qty,
                    'cost'  => (float) $i->cost,
                ])->values(),
                'transactions' => $transactions,
            ],
            'meta' => [
                'total_pcs'   => (int) $items->sum('qty'),
                'total_sales' => round((float) StockTransaction::where('type', StockTransaction::TYPE_CUSTOMER)->sum('total_amount'), 2),
                'grades'      => StockItem::GRADES,
            ],
            'message' => 'success',
        ]);
    }

    // ── POST /api/v1/admin/stock/supplier-invoice — finance.manage ───────────
    //
    // Stock in. Each line merges into the matching inventory line (brand +
    // size + grade, case-insensitive) or creates it; the latest unit cost
    // becomes the line's cost, exactly as finance's draft behaves.
    public function supplierInvoice(Request $request): JsonResponse
    {
        if (! Schema::hasTable('stock_items')) {
            return response()->json(['message' => 'The stock ledger is not migrated yet.'], 503);
        }

        $data = $request->validate([
            'supplier_name'      => ['required', 'string', 'max:190'],
            'invoice_no'         => ['required', 'string', 'max:100'],
            'lines'              => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.brand'      => ['required', 'string', 'max:100'],
            'lines.*.size'       => ['required', 'string', 'max:50'],
            'lines.*.tread'      => ['nullable', 'string', 'max:20'],
            'lines.*.grade'      => ['required', Rule::in(StockItem::GRADES)],
            'lines.*.qty'        => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_cost'  => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $transaction = DB::transaction(function () use ($data, $request) {
            $totalQty = 0;
            $totalAmount = 0.0;
            $lines = [];

            foreach ($data['lines'] as $line) {
                $brand = trim($line['brand']);
                $size  = strtoupper(trim($line['size']));

                $item = StockItem::whereRaw('LOWER(brand) = ?', [mb_strtolower($brand)])
                    ->whereRaw('LOWER(size) = ?', [mb_strtolower($size)])
                    ->where('grade', $line['grade'])
                    ->first();

                if ($item) {
                    $item->update([
                        'qty'   => $item->qty + $line['qty'],
                        'cost'  => round((float) $line['unit_cost'], 2),
                        'tread' => $line['tread'] ?? $item->tread,
                    ]);
                } else {
                    $item = StockItem::create([
                        'brand' => $brand,
                        'size'  => $size,
                        'tread' => $line['tread'] ?? null,
                        'grade' => $line['grade'],
                        'qty'   => $line['qty'],
                        'cost'  => round((float) $line['unit_cost'], 2),
                    ]);
                }

                $totalQty += $line['qty'];
                $totalAmount += $line['qty'] * (float) $line['unit_cost'];
                $lines[] = [
                    'stock_item_id' => $item->id,
                    'brand'         => $brand,
                    'size'          => $size,
                    'grade'         => $line['grade'],
                    'tread'         => $line['tread'] ?? null,
                    'qty'           => $line['qty'],
                    'unit_price'    => round((float) $line['unit_cost'], 2),
                ];
            }

            return StockTransaction::create([
                'type'         => StockTransaction::TYPE_SUPPLIER,
                'party_name'   => trim($data['supplier_name']),
                'reference'    => trim($data['invoice_no']),
                'total_qty'    => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'lines'        => $lines,
                'created_by'   => $request->user()?->id,
            ]);
        });

        AdminAuditLogger::info('stock_supplier_invoice', "Stock in: {$transaction->total_qty} pcs from {$transaction->party_name}", $request, $request->user(), [
            'transaction_id' => $transaction->id,
            'total_qty'      => $transaction->total_qty,
            'total_amount'   => (float) $transaction->total_amount,
        ]);

        return response()->json([
            'data'    => ['id' => $transaction->id],
            'message' => "Supplier invoice booked: {$transaction->total_qty} pcs into stock.",
        ], 201);
    }

    // ── POST /api/v1/admin/stock/customer-invoice — finance.manage ───────────
    //
    // Stock out. Quantities are checked against stock with the same-item
    // lines SUMMED first (two lines of the same tyre must not each pass
    // alone), rows locked while deducting, and the whole invoice is
    // refused on any shortage — a half-booked sale is worse than none.
    public function customerInvoice(Request $request): JsonResponse
    {
        if (! Schema::hasTable('stock_items')) {
            return response()->json(['message' => 'The stock ledger is not migrated yet.'], 503);
        }

        $data = $request->validate([
            'customer_name'        => ['required', 'string', 'max:190'],
            'contact'              => ['nullable', 'string', 'max:100'],
            'lines'                => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.stock_item_id' => ['required', 'integer'],
            'lines.*.qty'          => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price'   => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        try {
            $transaction = DB::transaction(function () use ($data, $request) {
                $wanted = collect($data['lines'])
                    ->groupBy('stock_item_id')
                    ->map(fn ($group) => $group->sum('qty'));

                $items = StockItem::whereIn('id', $wanted->keys())->lockForUpdate()->get()->keyBy('id');

                foreach ($wanted as $id => $qty) {
                    $item = $items->get($id);
                    if (! $item) {
                        throw new \RuntimeException("Stock line {$id} no longer exists.");
                    }
                    if ($qty > $item->qty) {
                        throw new \RuntimeException(
                            "Stock shortage for {$item->brand} {$item->size} ({$item->grade}): requested {$qty} pcs, only {$item->qty} in stock."
                        );
                    }
                }

                $totalQty = 0;
                $totalAmount = 0.0;
                $lines = [];

                foreach ($data['lines'] as $line) {
                    $item = $items->get($line['stock_item_id']);
                    $item->qty -= $line['qty'];
                    $item->save();

                    $totalQty += $line['qty'];
                    $totalAmount += $line['qty'] * (float) $line['unit_price'];
                    $lines[] = [
                        'stock_item_id' => $item->id,
                        'brand'         => $item->brand,
                        'size'          => $item->size,
                        'grade'         => $item->grade,
                        'qty'           => $line['qty'],
                        'unit_price'    => round((float) $line['unit_price'], 2),
                    ];
                }

                return StockTransaction::create([
                    'type'         => StockTransaction::TYPE_CUSTOMER,
                    'party_name'   => trim($data['customer_name']),
                    'reference'    => trim((string) ($data['contact'] ?? '')) ?: null,
                    'total_qty'    => $totalQty,
                    'total_amount' => round($totalAmount, 2),
                    'lines'        => $lines,
                    'created_by'   => $request->user()?->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AdminAuditLogger::info('stock_customer_invoice', "Stock out: {$transaction->total_qty} pcs to {$transaction->party_name}", $request, $request->user(), [
            'transaction_id' => $transaction->id,
            'total_qty'      => $transaction->total_qty,
            'total_amount'   => (float) $transaction->total_amount,
        ]);

        return response()->json([
            'data'    => ['id' => $transaction->id],
            'message' => "Sales invoice booked: {$transaction->total_qty} pcs out of stock.",
        ], 201);
    }
}
