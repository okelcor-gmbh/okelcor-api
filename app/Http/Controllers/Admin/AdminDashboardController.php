<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $today         = Carbon::today();
        $startOfMonth  = Carbon::now()->startOfMonth();
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $sevenDaysAgo  = Carbon::today()->subDays(6); // 6 back + today = 7 days inclusive

        // ------------------------------------------------------------------
        // Revenue today
        // ------------------------------------------------------------------
        $revenueToday = (float) Order::whereDate('created_at', $today)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->sum('total');

        // ------------------------------------------------------------------
        // Orders today
        // ------------------------------------------------------------------
        $ordersTodayTotal = Order::whereDate('created_at', $today)
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->count();

        $ordersTodayPaid = Order::whereDate('created_at', $today)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->count();

        // ------------------------------------------------------------------
        // Conversion rate
        // ------------------------------------------------------------------
        $conversionRate = $ordersTodayTotal > 0
            ? round($ordersTodayPaid / $ordersTodayTotal * 100, 2)
            : 0.0;

        // ------------------------------------------------------------------
        // Average order value — last 30 days, paid non-cancelled
        // Breakdown: Stripe live orders vs manual/imported orders
        // mode='manual' covers both Wix-imported and organic manual orders;
        // no separate imported_from column exists on orders table.
        // ------------------------------------------------------------------
        $aov = Order::where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->selectRaw("
                SUM(total)                                            AS total_sum,
                COUNT(*)                                              AS total_count,
                SUM(CASE WHEN payment_method = 'stripe' THEN 1 ELSE 0 END) AS stripe_count,
                SUM(CASE WHEN mode = 'manual'            THEN 1 ELSE 0 END) AS manual_count
            ")
            ->first();

        $aovTotalCount  = (int) ($aov->total_count  ?? 0);
        $aovStripeCount = (int) ($aov->stripe_count ?? 0);
        $aovManualCount = (int) ($aov->manual_count ?? 0);

        $averageOrderValue = ($aovTotalCount > 0)
            ? round((float) $aov->total_sum / $aovTotalCount, 2)
            : 0.0;

        // ------------------------------------------------------------------
        // New customers today — verified, not imported
        // ------------------------------------------------------------------
        $newCustomersToday = Customer::whereDate('created_at', $today)
            ->whereNotNull('email_verified_at')
            ->where('imported_from_wix', 0)
            ->count();

        // ------------------------------------------------------------------
        // Pending orders — awaiting processing
        // ------------------------------------------------------------------
        $pendingOrders = Order::whereIn('status', ['pending', 'confirmed'])
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->count();

        // ------------------------------------------------------------------
        // Confirmed revenue this month
        // ------------------------------------------------------------------
        $confirmedRevenueMonth = (float) Order::where('payment_status', 'paid')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('created_at', '>=', $startOfMonth)
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->sum('total');

        // ------------------------------------------------------------------
        // Pending revenue — orders placed but not yet paid
        // ------------------------------------------------------------------
        $pendingRevenue = (float) Order::where('payment_status', 'pending')
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->sum('total');

        // ------------------------------------------------------------------
        // Revenue last 7 days — grouped by date, zero-filled
        // ------------------------------------------------------------------
        $revenueByDate = Order::where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $sevenDaysAgo)
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->selectRaw('DATE(created_at) as date, SUM(total) as revenue')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->pluck('revenue', 'date')
            ->toArray();

        $revenueLast7Days = [];
        foreach (CarbonPeriod::create($sevenDaysAgo, $today) as $day) {
            $key = $day->format('Y-m-d');
            $revenueLast7Days[] = [
                'date'    => $key,
                'revenue' => round((float) ($revenueByDate[$key] ?? 0), 2),
            ];
        }

        // ------------------------------------------------------------------
        // Response
        // ------------------------------------------------------------------
        return response()->json([
            'data' => [
                'revenue_today'           => round($revenueToday, 2),
                'orders_today_total'      => $ordersTodayTotal,
                'orders_today_paid'       => $ordersTodayPaid,
                'conversion_rate'         => $conversionRate,
                'average_order_value'     => $averageOrderValue,
                'aov_period_label'        => 'last 30 days',
                'aov_paid_orders_count'   => $aovTotalCount,
                'aov_stripe_orders_count' => $aovStripeCount,
                'aov_manual_orders_count' => $aovManualCount,
                'new_customers_today'     => $newCustomersToday,
                'pending_orders'          => $pendingOrders,
                'confirmed_revenue_month' => round($confirmedRevenueMonth, 2),
                'pending_revenue'         => round($pendingRevenue, 2),
                'revenue_last_7_days'     => $revenueLast7Days,
            ],
            'message' => 'success',
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
        ]);
    }
}
