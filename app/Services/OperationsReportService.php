<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The transaction report: the board's figures repeated period by period, with
 * what changed between them.
 *
 * The board answers "how are we doing this month". This answers "compared to
 * what" — which is the question anyone actually asks second, and the one a
 * single-period board cannot be made to answer no matter how many columns it
 * grows.
 *
 * Chart-ready by construction. The series come out as parallel arrays with a
 * shared label axis, so the client plots them rather than re-aggregating
 * whatever it was given — the same choice the behaviour analytics made in
 * Session 79, and for the same reason: two places that aggregate are two places
 * that can disagree about a number the business is reading.
 */
class OperationsReportService
{
    private const CONFIRMED_STATUSES = ['confirmed', 'processing', 'shipped', 'delivered'];

    public const GRANULARITIES = ['day', 'week', 'month'];

    /** Plotted by default, in this order. */
    public const METRICS = ['orders_sent', 'orders_confirmed', 'amount', 'clients'];

    /**
     * @return array<string, mixed>
     */
    public function build(
        ?string $from = null,
        ?string $to = null,
        string $granularity = 'month',
        ?string $channel = null
    ): array {
        $granularity = in_array($granularity, self::GRANULARITIES, true) ? $granularity : 'month';

        [$start, $end] = $this->window($from, $to, $granularity);

        $buckets = $this->buckets($start, $end, $granularity);
        $rows    = $this->aggregate($start, $end, $granularity, $channel);

        $periods = [];

        foreach ($buckets as $key => $label) {
            $row = $rows[$key] ?? null;

            $periods[] = [
                'key'              => $key,
                'label'            => $label,
                'orders_sent'      => (int) ($row->orders_sent ?? 0),
                'orders_confirmed' => (int) ($row->orders_confirmed ?? 0),
                'amount'           => round((float) ($row->amount_eur ?? 0), 2),
                'currency'         => 'EUR',
                'clients'          => (int) ($row->clients ?? 0),
            ];
        }

        // Every bucket in the range is present, including the empty ones. A
        // gap-free axis is the difference between "we sold nothing in July" and
        // "July is missing from this chart", which look identical when the
        // empty buckets are simply absent.
        return [
            'period'      => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'granularity' => $granularity,
            'channel'     => $channel ?? 'all',
            'periods'     => $periods,
            'change'      => $this->change($periods),
            'totals'      => $this->totals($periods),
            'series'      => $this->series($periods),
            'note'        => 'Clients are counted distinctly WITHIN each period. Summing the client '
                . 'column across periods double-counts anyone who ordered in more than one — the '
                . 'total below is counted over the whole range instead.',
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Latest period against the one before it.
     *
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<string, mixed>|null
     */
    private function change(array $periods): ?array
    {
        if (count($periods) < 2) {
            return null;
        }

        $latest   = $periods[count($periods) - 1];
        $previous = $periods[count($periods) - 2];

        $out = ['from' => $previous['label'], 'to' => $latest['label'], 'metrics' => []];

        foreach (self::METRICS as $metric) {
            $was = $previous[$metric];
            $now = $latest[$metric];

            $out['metrics'][$metric] = [
                'previous' => $was,
                'current'  => $now,
                'delta'    => round($now - $was, 2),
                // Null rather than 0 or 100 when there is nothing to grow from.
                // A percentage change from zero is not a large number, it is an
                // undefined one, and rendering it as +100% reads as a fact.
                'percent'  => $was > 0 ? round((($now - $was) / $was) * 100, 1) : null,
                'direction' => $now > $was ? 'up' : ($now < $was ? 'down' : 'flat'),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<string, mixed>
     */
    private function totals(array $periods): array
    {
        return [
            'orders_sent'      => array_sum(array_column($periods, 'orders_sent')),
            'orders_confirmed' => array_sum(array_column($periods, 'orders_confirmed')),
            'amount'           => round(array_sum(array_column($periods, 'amount')), 2),
            'currency'         => 'EUR',
            'periods'          => count($periods),
            // Deliberately absent. See `note` — summing per-period client
            // counts double-counts returning buyers, and the honest total needs
            // its own query, which the controller supplies.
            'clients'          => null,
        ];
    }

    /**
     * Parallel arrays on a shared axis — what every charting library wants.
     *
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<string, mixed>
     */
    private function series(array $periods): array
    {
        return [
            'labels'   => array_column($periods, 'label'),
            'datasets' => array_map(fn (string $metric) => [
                'metric' => $metric,
                'label'  => match ($metric) {
                    'orders_sent'      => 'Orders sent',
                    'orders_confirmed' => 'Orders confirmed',
                    'amount'           => 'Amount (EUR)',
                    'clients'          => 'Clients',
                },
                'data' => array_map(fn ($p) => $p[$metric], $periods),
            ], self::METRICS),
        ];
    }

    /**
     * @return array<string, object>  keyed by bucket
     */
    private function aggregate(CarbonImmutable $start, CarbonImmutable $end, string $granularity, ?string $channel): array
    {
        $confirmed = "'" . implode("','", self::CONFIRMED_STATUSES) . "'";
        $bucket    = $this->bucketExpression($granularity);

        return Order::query()
            ->channel($channel)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS orders_sent')
            ->selectRaw("SUM(CASE WHEN status IN ({$confirmed}) THEN 1 ELSE 0 END) AS orders_confirmed")
            ->selectRaw("SUM(CASE WHEN COALESCE(currency,'EUR') = 'EUR' THEN total ELSE 0 END) AS amount_eur")
            ->selectRaw("COUNT(DISTINCT CASE WHEN status IN ({$confirmed}) THEN LOWER(customer_email) END) AS clients")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket')
            ->all();
    }

    /**
     * Bucketing is done in SQL, and the expression differs by driver — sqlite
     * has no DATE_FORMAT and MySQL has no strftime. Written for both rather
     * than for whichever one the test harness happens to use, because a report
     * that only works on one of them is found in production.
     */
    private function bucketExpression(string $granularity): string
    {
        $mysql = DB::getDriverName() === 'mysql';

        return match ($granularity) {
            'day'   => $mysql ? "DATE_FORMAT(created_at, '%Y-%m-%d')" : "strftime('%Y-%m-%d', created_at)",
            'week'  => $mysql ? "DATE_FORMAT(created_at, '%x-W%v')"   : "strftime('%Y-W%W', created_at)",
            default => $mysql ? "DATE_FORMAT(created_at, '%Y-%m')"    : "strftime('%Y-%m', created_at)",
        };
    }

    /**
     * Every bucket between the two dates, so an empty period is a zero rather
     * than a missing point.
     *
     * @return array<string, string>  bucket key => human label
     */
    private function buckets(CarbonImmutable $start, CarbonImmutable $end, string $granularity): array
    {
        $out    = [];
        $cursor = $start;

        while ($cursor->lte($end)) {
            [$key, $label] = match ($granularity) {
                'day'   => [$cursor->format('Y-m-d'), $cursor->format('j M Y')],
                'week'  => [$cursor->format('o-\WW'), 'Week ' . $cursor->format('W, o')],
                default => [$cursor->format('Y-m'), $cursor->format('M Y')],
            };

            $out[$key] = $label;

            $cursor = match ($granularity) {
                'day'   => $cursor->addDay(),
                'week'  => $cursor->addWeek(),
                default => $cursor->addMonth(),
            };
        }

        return $out;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(?string $from, ?string $to, string $granularity): array
    {
        $end = $to ? CarbonImmutable::parse($to) : CarbonImmutable::today();

        // Six months back by default. One month is a board, not a report — the
        // question this exists to answer needs at least a previous period to
        // compare against.
        $start = $from ? CarbonImmutable::parse($from) : match ($granularity) {
            'day'   => $end->subDays(29),
            'week'  => $end->subWeeks(11),
            default => $end->subMonths(5)->startOfMonth(),
        };

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }
}
