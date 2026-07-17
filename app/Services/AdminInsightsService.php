<?php

namespace App\Services;

use App\Models\AdminInsight;
use App\Models\AdminLoginHistory;
use App\Models\AdminSecurityEvent;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QuoteRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns the same aggregate numbers already shown across the admin dashboard,
 * security summary, and quotes list into a handful of short, plain-English
 * observations via Gemini — a periodic summarization pass, not a new data
 * source. See FRONTEND_NOTE_admin-insights.md for the full design.
 *
 * Data minimization: buildSnapshot() below is the entire universe of what
 * gets sent to Gemini. It contains only aggregate counts, sums, and category
 * labels (country names, tyre types, status counts) — never a customer
 * name, email, address, or admin identity. Keep it that way when extending.
 *
 * Numeric forecasts (e.g. days-to-stockout) are computed here in PHP, not
 * left for the model to estimate — the prompt explicitly instructs Gemini to
 * restate provided numbers, not invent its own, so a business-critical
 * figure is never a hallucination.
 *
 * Degrades silently at every failure point (missing API key, HTTP error,
 * malformed response) — a failed cycle simply doesn't insert new rows, so
 * GET /admin/insights keeps serving whatever the last successful cycle
 * produced, same graceful-degradation pattern as every other optional
 * integration in this app (gls/whatsapp/dhl).
 */
class AdminInsightsService
{
    private const CATEGORIES    = ['revenue', 'orders', 'inventory', 'security', 'quotes'];
    private const SEVERITIES    = ['positive', 'info', 'warning', 'critical'];
    private const MAX_INSIGHTS  = 4;
    private const RETENTION_DAYS = 30;

    public function generate(): void
    {
        if (! config('services.gemini.api_key')) {
            return;
        }

        $snapshot = $this->buildSnapshot();

        try {
            $insights = $this->callGemini($snapshot);
        } catch (\Throwable $e) {
            Log::warning('[admin_insights_generation_failed] Gemini call failed — keeping previous insights', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (! $insights) {
            return;
        }

        $this->persist($insights);
        $this->prune();
    }

    // ── Snapshot builders ─────────────────────────────────────────────────────

    private function buildSnapshot(): array
    {
        return [
            'revenue_and_orders' => $this->revenueAndOrdersSnapshot(),
            'inventory'          => $this->inventorySnapshot(),
            'security'           => $this->securitySnapshot(),
            'quotes'             => $this->quotesSnapshot(),
        ];
    }

    private function revenueAndOrdersSnapshot(): array
    {
        $today     = Carbon::today();
        $yesterday = Carbon::yesterday();

        $paidNotCancelled = fn ($q) => $q->where('payment_status', 'paid')->where('status', '!=', 'cancelled');

        $revenueToday     = (float) $paidNotCancelled(Order::whereDate('created_at', $today))->sum('total');
        $revenueYesterday = (float) $paidNotCancelled(Order::whereDate('created_at', $yesterday))->sum('total');
        $ordersToday      = $paidNotCancelled(Order::whereDate('created_at', $today))->count();
        $ordersYesterday  = $paidNotCancelled(Order::whereDate('created_at', $yesterday))->count();
        $pendingOrders    = Order::whereIn('status', ['pending', 'confirmed'])->count();

        $countryBreakdownToday = $paidNotCancelled(Order::whereDate('created_at', $today))
            ->select('country', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(total) as total_value'))
            ->groupBy('country')
            ->orderByDesc('total_value')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['country' => $r->country, 'order_count' => (int) $r->order_count, 'total_value' => round((float) $r->total_value, 2)])
            ->values()->all();

        $typeBreakdownToday = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereDate('orders.created_at', $today)
            ->where('orders.payment_status', 'paid')
            ->where('orders.status', '!=', 'cancelled')
            ->select('products.type as type', DB::raw('SUM(order_items.quantity) as qty'), DB::raw('SUM(order_items.line_total) as value'))
            ->groupBy('products.type')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'quantity' => (int) $r->qty, 'value' => round((float) $r->value, 2)])
            ->values()->all();

        return [
            'revenue_today'                 => round($revenueToday, 2),
            'revenue_yesterday'              => round($revenueYesterday, 2),
            'revenue_pct_change_vs_yesterday' => $revenueYesterday > 0
                ? round((($revenueToday - $revenueYesterday) / $revenueYesterday) * 100, 1)
                : null,
            'orders_today'                  => $ordersToday,
            'orders_yesterday'               => $ordersYesterday,
            'pending_orders'                 => $pendingOrders,
            'country_breakdown_today'       => $countryBreakdownToday,
            'tyre_type_breakdown_today'     => $typeBreakdownToday,
        ];
    }

    private function inventorySnapshot(): array
    {
        $sevenDaysAgo = Carbon::now()->subDays(7);

        $salesVelocity = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.payment_status', 'paid')
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.created_at', '>=', $sevenDaysAgo)
            ->whereNotNull('order_items.product_id')
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as qty_sold_7d'))
            ->groupBy('order_items.product_id')
            ->get()
            ->keyBy('product_id');

        $atRisk = [];
        if ($salesVelocity->isNotEmpty()) {
            $products = Product::whereIn('id', $salesVelocity->keys())->where('is_active', true)->get();

            foreach ($products as $product) {
                $dailyRate = ((int) $salesVelocity[$product->id]->qty_sold_7d) / 7;
                if ($dailyRate <= 0 || $product->stock === null || $product->stock <= 0) {
                    continue;
                }

                $daysToStockout = $product->stock / $dailyRate;
                if ($daysToStockout <= 10) {
                    $atRisk[] = [
                        'sku'              => $product->sku,
                        'brand'            => $product->brand,
                        'size'             => $product->size,
                        'type'             => $product->type,
                        'current_stock'    => $product->stock,
                        'daily_sales_rate' => round($dailyRate, 2),
                        'days_to_stockout' => round($daysToStockout, 1),
                    ];
                }
            }

            usort($atRisk, fn ($a, $b) => $a['days_to_stockout'] <=> $b['days_to_stockout']);
            $atRisk = array_slice($atRisk, 0, 5);
        }

        return [
            'low_stock_product_count' => Product::where('is_active', true)->where('stock', '<=', 5)->count(),
            'at_risk_of_stockout'     => $atRisk,
        ];
    }

    private function securitySnapshot(): array
    {
        $today = now()->startOfDay();

        $totalAdmins  = AdminUser::where('is_active', true)->count();
        $twoFaEnabled = AdminUser::where('is_active', true)->whereNotNull('two_factor_confirmed_at')->count();

        return [
            'two_fa_adoption_pct'  => $totalAdmins > 0 ? round(($twoFaEnabled / $totalAdmins) * 100, 1) : 0,
            'failed_logins_today'  => AdminLoginHistory::where('success', false)->where('created_at', '>=', $today)->count(),
            'critical_events_today' => AdminSecurityEvent::where('severity', 'critical')->where('created_at', '>=', $today)->count(),
            'permission_denied_today' => AdminSecurityEvent::where('type', 'permission_denied')->where('created_at', '>=', $today)->count(),
        ];
    }

    private function quotesSnapshot(): array
    {
        $today = Carbon::today();

        $categoryBreakdown = QuoteRequest::whereDate('created_at', $today)
            ->select('tyre_category', DB::raw('COUNT(*) as count'))
            ->groupBy('tyre_category')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['category' => $r->tyre_category, 'count' => (int) $r->count])
            ->values()->all();

        return [
            'new_quotes_today'          => QuoteRequest::whereDate('created_at', $today)->count(),
            'open_quotes_total'         => QuoteRequest::whereIn('status', ['new', 'reviewing'])->count(),
            'category_breakdown_today'  => $categoryBreakdown,
        ];
    }

    // ── Gemini call ───────────────────────────────────────────────────────────

    private function callGemini(array $snapshot): array
    {
        $prompt = $this->buildPrompt($snapshot);

        $url = rtrim(config('services.gemini.base_url'), '/')
            . '/models/' . config('services.gemini.model') . ':generateContent'
            . '?key=' . config('services.gemini.api_key');

        $response = Http::timeout(30)->post($url, [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature'      => 0.4,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $this->responseSchema(),
            ],
        ]);

        if (! $response->ok()) {
            throw new \RuntimeException('Gemini API error ' . $response->status() . ': ' . $response->body());
        }

        $raw = $response->json('candidates.0.content.parts.0.text');
        if (! $raw) {
            throw new \RuntimeException('Gemini response had no content.');
        }

        $decoded = json_decode($raw, true);
        $items   = $decoded['insights'] ?? null;

        if (! is_array($items) || ! $items) {
            throw new \RuntimeException('Gemini response did not contain a valid insights array.');
        }

        return $this->sanitizeInsights($items);
    }

    private function buildPrompt(array $snapshot): string
    {
        $json = json_encode($snapshot, JSON_PRETTY_PRINT);

        return <<<PROMPT
            You are summarizing internal business metrics for a B2B tyre wholesale
            company's admin dashboard. Below is a JSON snapshot of real aggregate
            numbers (revenue, orders, inventory, security, quotes) for the current
            period. Turn this into 2 to 4 short, plain-English observations an
            admin would find genuinely useful at a glance — not a restatement of
            every number, just what actually stands out.

            Rules:
            - Use ONLY the numbers given below. Never invent, estimate, or round
              a number yourself beyond what is already provided — if a figure
              like "days_to_stockout" is given, restate it as-is.
            - Each observation needs: category (one of: revenue, orders,
              inventory, security, quotes), severity (positive, info, warning,
              or critical), a short headline (under 12 words), and one sentence
              of detail giving the concrete numbers behind it.
            - Rank observations most-important-first.
            - If nothing in a category stands out, omit that category entirely
              rather than forcing an observation.
            - Do not mention any customer, admin, or individual by name — only
              the aggregate figures given.

            Snapshot:
            {$json}
            PROMPT;
    }

    private function responseSchema(): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'insights' => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'category'   => ['type' => 'STRING', 'enum' => self::CATEGORIES],
                            'severity'   => ['type' => 'STRING', 'enum' => self::SEVERITIES],
                            'headline'   => ['type' => 'STRING'],
                            'detail'     => ['type' => 'STRING'],
                            'action_url' => ['type' => 'STRING'],
                        ],
                        'required' => ['category', 'severity', 'headline', 'detail'],
                    ],
                ],
            ],
            'required' => ['insights'],
        ];
    }

    private function sanitizeInsights(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $category = $item['category'] ?? null;
            $severity = $item['severity'] ?? null;
            $headline = trim((string) ($item['headline'] ?? ''));
            $detail   = trim((string) ($item['detail'] ?? ''));

            if (! in_array($category, self::CATEGORIES, true)
                || ! in_array($severity, self::SEVERITIES, true)
                || $headline === ''
                || $detail === ''
            ) {
                continue;
            }

            $clean[] = [
                'category'   => $category,
                'severity'   => $severity,
                'headline'   => mb_substr($headline, 0, 280),
                'detail'     => mb_substr($detail, 0, 2000),
                'action_url' => ! empty($item['action_url']) ? mb_substr((string) $item['action_url'], 0, 490) : null,
            ];

            if (count($clean) >= self::MAX_INSIGHTS) {
                break;
            }
        }

        return $clean;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    private function persist(array $insights): void
    {
        $generatedAt = now();

        foreach ($insights as $index => $insight) {
            AdminInsight::create([
                'external_id'  => 'ins_' . $generatedAt->format('Ymd_His') . '_' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'category'     => $insight['category'],
                'severity'     => $insight['severity'],
                'headline'     => $insight['headline'],
                'detail'       => $insight['detail'],
                'action_url'   => $insight['action_url'],
                'rank'         => $index,
                'generated_at' => $generatedAt,
            ]);
        }
    }

    private function prune(): void
    {
        AdminInsight::where('generated_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }
}
