<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The clients behind the board's "Clients" figure, and what each of them
 * actually bought.
 *
 * A count on a board is only useful if you can open it. "4 clients" is a number
 * to be trusted or doubted; four names with what they spent is something to act
 * on — and it is the only way anyone can check the figure without asking a
 * developer to run a query.
 *
 * A client here is a distinct lower-cased `customer_email` on a confirmed
 * order. That is the same definition the board counts with, deliberately: two
 * definitions of "client" that disagree by one is exactly the kind of thing two
 * departments spend a morning on.
 */
class OperationsClientService
{
    /** The same statuses the board treats as confirmed business. */
    private const CONFIRMED_STATUSES = ['confirmed', 'processing', 'shipped', 'delivered'];

    /**
     * @return array<string, mixed>
     */
    public function list(
        ?string $from = null,
        ?string $to = null,
        ?string $channel = null,
        ?string $search = null,
        string $sort = 'amount',
        int $perPage = 25,
        int $page = 1
    ): array {
        [$start, $end] = $this->window($from, $to);

        $rows = $this->baseQuery($start, $end, $channel)
            ->when($search, fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            }))
            ->selectRaw(implode(', ', [
                'LOWER(customer_email) AS email_key',
                'MAX(customer_name) AS customer_name',
                'MAX(country) AS country',
                'COUNT(*) AS orders_count',
                // Only EUR is summed. The board makes the same choice and for
                // the same reason: a historic figure that moves with today's
                // exchange rate is not a figure.
                "SUM(CASE WHEN COALESCE(currency, 'EUR') = 'EUR' THEN total ELSE 0 END) AS amount_eur",
                "SUM(CASE WHEN COALESCE(currency, 'EUR') <> 'EUR' THEN 1 ELSE 0 END) AS other_currency_orders",
                'MIN(created_at) AS first_order_at',
                'MAX(created_at) AS last_order_at',
                "SUM(CASE WHEN source = 'ebay' THEN 1 ELSE 0 END) AS ebay_orders",
            ]))
            ->groupBy('email_key')
            ->get();

        $sorted = $this->sort($rows, $sort);
        $total  = $sorted->count();
        $slice  = $sorted->forPage($page, $perPage)->values();

        // One lookup for the whole page rather than one per row: orders carry
        // no customer foreign key, so this is a join done in PHP and it is easy
        // to make it N+1 by accident.
        $customers = Customer::whereIn('email', $slice->pluck('email_key')->all())
            ->get(['id', 'email', 'company_name', 'buyer_tier', 'onboarding_status'])
            ->keyBy(fn ($c) => strtolower($c->email));

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'clients' => $slice->map(function ($row) use ($customers) {
                $customer = $customers[$row->email_key] ?? null;

                return [
                    'email'          => $row->email_key,
                    'name'           => $row->customer_name,
                    'country'        => $row->country,
                    'orders_count'   => (int) $row->orders_count,
                    'amount'         => round((float) $row->amount_eur, 2),
                    'currency'       => 'EUR',
                    // Flagged rather than folded in, so a client whose figure
                    // is understated says so instead of quietly reading low.
                    'other_currency_orders' => (int) $row->other_currency_orders,
                    'first_order_at' => $this->iso($row->first_order_at),
                    'last_order_at'  => $this->iso($row->last_order_at),
                    'channels'       => $this->channels($row),
                    // Present only when the e-mail matches an actual account —
                    // plenty of confirmed orders belong to buyers who never
                    // registered, and inventing a customer id for them would
                    // give the UI a link that 404s.
                    'customer_id'       => $customer?->id,
                    'company'           => $customer?->company_name,
                    'buyer_tier'        => $customer?->buyer_tier,
                    'onboarding_status' => $customer?->onboarding_status,
                    'has_account'       => $customer !== null,
                ];
            })->all(),
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / max(1, $perPage))),
                'sort'         => $sort,
                'definition'   => 'A distinct e-mail address on a confirmed order in the period — '
                    . 'the same definition the board counts with.',
            ],
        ];
    }

    /**
     * One client's orders in the period.
     *
     * @return array<string, mixed>|null  null when that address has no orders here
     */
    public function detail(string $email, ?string $from = null, ?string $to = null, ?string $channel = null): ?array
    {
        [$start, $end] = $this->window($from, $to);

        $email = strtolower(trim($email));

        $orders = $this->baseQuery($start, $end, $channel)
            ->whereRaw('LOWER(customer_email) = ?', [$email])
            ->orderByDesc('created_at')
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        $customer = Customer::whereRaw('LOWER(email) = ?', [$email])->first();

        return [
            'email'   => $email,
            'name'    => $orders->first()->customer_name,
            'country' => $orders->first()->country,
            'customer_id'       => $customer?->id,
            'company'           => $customer?->company_name,
            'buyer_tier'        => $customer?->buyer_tier,
            'onboarding_status' => $customer?->onboarding_status,
            'has_account'       => $customer !== null,
            'period'  => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'totals'  => [
                'orders_count' => $orders->count(),
                'amount'       => round((float) $orders->filter(fn ($o) => ($o->currency ?: 'EUR') === 'EUR')->sum('total'), 2),
                'currency'     => 'EUR',
                'in_transit'   => $orders->filter(fn ($o) => $o->isInTransit())->count(),
            ],
            'orders' => $orders->map(fn (Order $o) => [
                'id'             => $o->id,
                'order_ref'      => $o->ref,
                'channel'        => $o->channel(),
                'status'         => $o->status,
                'payment_status' => $o->payment_status,
                'payment_stage'  => $o->payment_stage,
                'total'          => (float) $o->total,
                'currency'       => $o->currency ?: 'EUR',
                'in_transit'     => $o->isInTransit(),
                'created_at'     => $o->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    // -------------------------------------------------------------------------

    private function baseQuery(CarbonImmutable $start, CarbonImmutable $end, ?string $channel)
    {
        return Order::query()
            ->channel($channel)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->whereNotNull('customer_email')
            ->where('customer_email', '!=', '')
            // Stripe's test checkouts are real rows and are not real orders.
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'));
    }

    private function sort($rows, string $sort)
    {
        return match ($sort) {
            'orders' => $rows->sortByDesc(fn ($r) => (int) $r->orders_count)->values(),
            'recent' => $rows->sortByDesc(fn ($r) => (string) $r->last_order_at)->values(),
            'name'   => $rows->sortBy(fn ($r) => strtolower((string) $r->customer_name))->values(),
            default  => $rows->sortByDesc(fn ($r) => (float) $r->amount_eur)->values(),
        };
    }

    /** @return array<int, string> */
    private function channels($row): array
    {
        $ebay   = (int) $row->ebay_orders;
        $total  = (int) $row->orders_count;
        $out    = [];

        if ($total - $ebay > 0) {
            $out[] = Order::CHANNEL_NORMAL;
        }

        if ($ebay > 0) {
            $out[] = Order::CHANNEL_EBAY;
        }

        return $out;
    }

    private function iso(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(?string $from, ?string $to): array
    {
        $end   = $to ? CarbonImmutable::parse($to) : CarbonImmutable::today();
        $start = $from ? CarbonImmutable::parse($from) : $end->startOfMonth();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }
}
