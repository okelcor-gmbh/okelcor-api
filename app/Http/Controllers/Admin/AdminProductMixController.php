<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Promotion insight, built from the marketing team's proposal: should we
 * promote used or new tyres, and which sizes should become bundles in
 * which countries?
 *
 * Two honest definitions this report stands on:
 *  - condition: products.type 'used' is used; pcr/tbr/otr are new. An
 *    order line whose SKU matches no product is 'unknown' and counted as
 *    such rather than guessed.
 *  - "satisfied customer": someone who ordered more than once. The system
 *    holds no satisfaction ratings — coming back is the one satisfaction
 *    signal the data actually records.
 */
class AdminProductMixController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 90);
        $days = in_array($days, [30, 90, 180, 365], true) ? $days : 90;
        $from = now()->subDays($days);

        // Order lines in the window, with their order's country/channel/buyer.
        $lines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.created_at', '>=', $from)
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'order_items.product_id', 'order_items.sku', 'order_items.quantity',
                'order_items.unit_price', 'order_items.line_total',
                'orders.id as order_id', 'orders.country', 'orders.source', 'orders.customer_email',
            )
            ->get();

        // Product lookup by id AND by sku (eBay-imported lines carry sku only).
        $products = DB::table('products')
            ->select('id', 'sku', 'type', 'size', 'brand', 'cost_price')
            ->get();
        $byId  = $products->keyBy('id');
        $bySku = $products->filter(fn ($p) => $p->sku !== null)->keyBy('sku');

        // Customers who came back: >1 non-cancelled order, all-time.
        $repeatEmails = DB::table('orders')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('customer_email')
            ->groupBy('customer_email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('customer_email')
            ->flip();

        $conditions = [];
        $sizes      = [];
        $unknown    = 0;

        foreach ($lines as $line) {
            $product = ($line->product_id !== null ? $byId->get($line->product_id) : null)
                ?? ($line->sku !== null ? $bySku->get($line->sku) : null);

            $condition = $product === null
                ? 'unknown'
                : (strtolower((string) $product->type) === 'used' ? 'used' : 'new');
            if ($product === null) {
                $unknown++;
            }

            $qty     = max(1, (int) $line->quantity);
            $revenue = (float) $line->line_total;
            $margin  = ($product !== null && $product->cost_price !== null)
                ? ((float) $line->unit_price - (float) $product->cost_price) * $qty
                : null;

            // ── Condition split ──────────────────────────────────────────
            $c = &$conditions[$condition];
            $c ??= ['units' => 0, 'revenue' => 0.0, 'orders' => [], 'margin' => 0.0, 'margin_units' => 0,
                    'channels' => ['website' => 0, 'ebay' => 0]];
            $c['units']   += $qty;
            $c['revenue'] += $revenue;
            $c['orders'][$line->order_id] = true;
            if ($margin !== null) {
                $c['margin']       += $margin;
                $c['margin_units'] += $qty;
            }
            $channel = $line->source === 'ebay' ? 'ebay' : 'website';
            $c['channels'][$channel] += $qty;
            unset($c);

            // ── Size performance (only lines we can identify) ────────────
            if ($product === null || empty($product->size)) {
                continue;
            }
            $key = $condition . '|' . strtoupper((string) $product->type) . '|' . $product->size;
            $s = &$sizes[$key];
            $s ??= ['condition' => $condition, 'type' => strtoupper((string) $product->type),
                    'size' => $product->size, 'units' => 0, 'revenue' => 0.0,
                    'countries' => [], 'repeat_units' => 0, 'repeat_customers' => []];
            $s['units']   += $qty;
            $s['revenue'] += $revenue;
            $country = trim((string) $line->country) ?: 'Unknown';
            $s['countries'][$country] = ($s['countries'][$country] ?? 0) + $qty;
            if ($line->customer_email !== null && isset($repeatEmails[$line->customer_email])) {
                $s['repeat_units'] += $qty;
                $s['repeat_customers'][$line->customer_email] = true;
            }
            unset($s);
        }

        $conditionRows = collect($conditions)->map(fn ($c, $condition) => [
            'condition'      => $condition,
            'units'          => $c['units'],
            'revenue'        => round($c['revenue'], 2),
            'orders'         => count($c['orders']),
            'est_margin'     => $c['margin_units'] > 0 ? round($c['margin'], 2) : null,
            'margin_units'   => $c['margin_units'],
            'channels'       => $c['channels'],
        ])->sortByDesc('revenue')->values();

        $sizeRows = collect($sizes)->map(function ($s) {
            arsort($s['countries']);

            return [
                'condition'        => $s['condition'],
                'type'             => $s['type'],
                'size'             => $s['size'],
                'units'            => $s['units'],
                'revenue'          => round($s['revenue'], 2),
                'repeat_units'     => $s['repeat_units'],
                'repeat_customers' => count($s['repeat_customers']),
                'countries'        => collect($s['countries'])->take(3)
                    ->map(fn ($units, $country) => ['country' => $country, 'units' => $units])
                    ->values(),
            ];
        })->sortByDesc('units')->take(25)->values();

        // Bundle suggestions: sizes that repeat buyers keep coming back for,
        // pinned to the country where that demand actually lives.
        $bundles = $sizeRows
            ->filter(fn ($s) => $s['repeat_customers'] >= 2 && $s['countries']->isNotEmpty())
            ->take(10)
            ->map(fn ($s) => [
                'condition' => $s['condition'],
                'type'      => $s['type'],
                'size'      => $s['size'],
                'country'   => $s['countries'][0]['country'],
                'evidence'  => "{$s['repeat_customers']} repeat buyer(s), {$s['repeat_units']} units from them",
                'suggestion' => ucfirst($s['condition']) . " {$s['type']} {$s['size']} bundle — promote in {$s['countries'][0]['country']}",
            ])->values();

        return response()->json([
            'data' => [
                'by_condition' => $conditionRows,
                'top_sizes'    => $sizeRows,
                'bundles'      => $bundles,
            ],
            'meta' => [
                'window_days'          => $days,
                'unknown_lines'        => $unknown,
                'repeat_customer_count' => $repeatEmails->count(),
                'satisfied_definition' => 'Customers with more than one non-cancelled order — repeat purchase is the satisfaction signal the data records.',
            ],
            'message' => 'success',
        ]);
    }
}
