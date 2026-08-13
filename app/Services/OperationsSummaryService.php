<?php

namespace App\Services;

use App\Models\FinanceInvoice;
use App\Models\Invoice;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The operations board: the finance director's grid, one row per sales channel.
 *
 * Orders sent, what they were worth, how many clients they came from, how many
 * were confirmed, how many invoices this system raised, how many the finance
 * system has, and how many are in transit.
 *
 * Two decisions run through the whole class.
 *
 * **Every figure declares how it was counted.** A board of seven numbers that
 * two departments will argue over is worthless if "orders sent" means something
 * different to the person reading it than to the query. Each metric carries a
 * `basis` string, and the API returns the definitions alongside the numbers.
 *
 * **The invoice columns are meant to disagree.** Website and finance-system
 * counts sitting side by side exist to produce a variance. Showing them without
 * the difference would let a mismatch sit on screen looking like two facts.
 */
class OperationsSummaryService
{
    /**
     * How each column is calculated, in the words the board should carry.
     *
     * @return array<string, string>
     */
    public const DEFINITIONS = [
        'orders_sent'       => 'Orders raised in the period, excluding cancelled and Stripe test checkouts.',
        'amount'            => 'Sum of order totals for those orders, in EUR. Orders in another currency are counted separately and named.',
        'clients'           => 'Distinct customers behind the CONFIRMED orders — an enquiry that never became an order is not a client.',
        'orders_confirmed'  => 'Orders that reached confirmed, processing, shipped or delivered. Not cancelled, not still pending.',
        'website_invoices'  => 'Invoices this system raised, by issue date.',
        'finance_invoices'  => 'Invoices finance entered from sevDesk, by issue date.',
        'invoice_variance'  => 'Website invoices minus finance invoices. Anything other than zero is one system holding an invoice the other does not.',
        'in_transit'        => 'Paid and dispatched, not yet delivered — the orders whose trade documents need sending.',
    ];

    /** Order statuses that count as confirmed business. */
    private const CONFIRMED_STATUSES = ['confirmed', 'processing', 'shipped', 'delivered'];

    /**
     * @return array<string, mixed>
     */
    public function build(?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->window($from, $to);

        $rows = [];

        foreach (Order::CHANNELS as $channel) {
            $rows[] = $this->row($channel, $start, $end);
        }

        return [
            'period' => [
                'from'  => $start->toDateString(),
                'to'    => $end->toDateString(),
                'label' => $start->toDateString() . ' → ' . $end->toDateString(),
            ],
            'channels'    => $rows,
            'total'       => $this->total($rows, $start, $end),
            'definitions' => self::DEFINITIONS,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(?string $from, ?string $to): array
    {
        $end = $to ? CarbonImmutable::parse($to) : CarbonImmutable::today();
        // A month is the period finance actually reconciles in; defaulting to
        // anything shorter would show a variance that is simply "the rest of
        // the month has not happened yet".
        $start = $from ? CarbonImmutable::parse($from) : $end->startOfMonth();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $channel, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $inPeriod = fn () => Order::query()
            ->channel($channel)
            ->whereBetween('created_at', [$start, $end])
            // Stripe's test checkouts are real rows and are not real orders.
            // The dashboard has excluded them since Session 24; the board would
            // be reporting different totals from the dashboard without this.
            ->where(fn ($q) => $q->whereNull('payment_session_id')
                ->orWhere('payment_session_id', 'not like', 'cs_test_%'));

        $ordersSent = (clone $inPeriod())->where('status', '!=', 'cancelled')->count();

        $confirmed = (clone $inPeriod())->whereIn('status', self::CONFIRMED_STATUSES);

        $amounts  = $this->amounts((clone $inPeriod())->where('status', '!=', 'cancelled'));
        $clients  = $this->clients((clone $confirmed));

        $websiteInvoices = $this->websiteInvoices($channel, $start, $end);
        $financeInvoices = $this->financeInvoices($channel, $start, $end);

        return [
            'channel'       => $channel,
            'label'         => $channel === Order::CHANNEL_EBAY ? 'eBay' : 'Normal',
            'orders_sent'   => $ordersSent,
            'amount'        => $amounts['eur'],
            'currency'      => 'EUR',
            // Not folded into `amount` at an invented rate. An order booked in
            // USD converted with today's rate would make a historic month's
            // revenue change every time the board is opened.
            'amount_other_currencies' => $amounts['other'],
            'clients'          => $clients,
            'orders_confirmed' => (clone $confirmed)->count(),
            'website_invoices' => $websiteInvoices,
            'finance_invoices' => $financeInvoices,
            'invoice_variance' => $websiteInvoices - $financeInvoices,
            'in_transit'       => Order::query()->channel($channel)->inTransit()->count(),
        ];
    }

    /**
     * Revenue in EUR, plus anything booked in another currency listed rather
     * than converted.
     *
     * @return array{eur: float, other: array<int, array{currency: string, amount: float, orders: int}>}
     */
    private function amounts($query): array
    {
        $rows = (clone $query)
            ->selectRaw('COALESCE(currency, ?) AS cur, SUM(total) AS amount, COUNT(*) AS orders', ['EUR'])
            ->groupBy('cur')
            ->get();

        $eur   = 0.0;
        $other = [];

        foreach ($rows as $row) {
            $currency = strtoupper((string) $row->cur);

            if ($currency === 'EUR' || $currency === '') {
                $eur += (float) $row->amount;
                continue;
            }

            $other[] = [
                'currency' => $currency,
                'amount'   => round((float) $row->amount, 2),
                'orders'   => (int) $row->orders,
            ];
        }

        return ['eur' => round($eur, 2), 'other' => $other];
    }

    /**
     * Distinct customers behind the confirmed orders.
     *
     * Counted on e-mail because that is what links an order to a customer
     * throughout this codebase — orders carry no customer FK. Lower-cased, so
     * one buyer who typed their address two ways is one client rather than two.
     */
    private function clients($query): int
    {
        return (int) (clone $query)
            ->whereNotNull('customer_email')
            ->where('customer_email', '!=', '')
            ->distinct()
            ->count(DB::raw('LOWER(customer_email)'));
    }

    /**
     * Invoices this system raised in the period, for this channel.
     *
     * Invoices link to orders by `order_ref` string, so the channel comes from
     * the order behind the invoice. An invoice whose order has vanished is
     * counted under `normal` rather than dropped — losing it would make the
     * variance smaller than the truth, which is the one direction of error this
     * board must not have.
     */
    private function websiteInvoices(string $channel, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $refs = Order::query()->channel(Order::CHANNEL_EBAY)->pluck('ref')->all();

        $query = Invoice::query()->whereBetween('issued_at', [$start, $end]);

        if ($channel === Order::CHANNEL_EBAY) {
            return $refs === [] ? 0 : (int) $query->whereIn('order_ref', $refs)->count();
        }

        return (int) ($refs === [] ? $query->count() : $query->whereNotIn('order_ref', $refs)->count());
    }

    /**
     * Invoices finance entered from sevDesk.
     *
     * Guarded on the table existing so the board still renders — with a zero
     * and, at the API layer, a flag saying recording is not live yet — between
     * the code deploying and the migration running. A blank column is a
     * question; a 500 is an outage.
     */
    private function financeInvoices(string $channel, CarbonImmutable $start, CarbonImmutable $end): int
    {
        if (! Schema::hasTable('finance_invoices')) {
            return 0;
        }

        return (int) FinanceInvoice::query()
            ->where('channel', $channel)
            // whereDate, not whereBetween on the raw column: `issued_on` is a
            // DATE in MySQL but Eloquent's date cast writes a full timestamp on
            // sqlite, and '2026-08-13 00:00:00' compares as GREATER than
            // '2026-08-13' as a string — so the last day of every period fell
            // out of the count, on the test harness only. A figure that is
            // right in production and wrong in CI is worse than one that is
            // wrong in both.
            ->whereDate('issued_on', '>=', $start->toDateString())
            ->whereDate('issued_on', '<=', $end->toDateString())
            ->count();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function total(array $rows, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sum = fn (string $key) => array_sum(array_column($rows, $key));

        return [
            'channel'          => 'all',
            'label'            => 'All channels',
            'orders_sent'      => $sum('orders_sent'),
            'amount'           => round((float) $sum('amount'), 2),
            'currency'         => 'EUR',
            'orders_confirmed' => $sum('orders_confirmed'),
            'website_invoices' => $sum('website_invoices'),
            'finance_invoices' => $sum('finance_invoices'),
            'invoice_variance' => $sum('invoice_variance'),
            'in_transit'       => $sum('in_transit'),
            // NOT the sum of the per-channel counts: one buyer who ordered on
            // eBay and on the website is one client, and adding the rows would
            // report two. Counted distinctly across both channels instead.
            'clients'          => $this->clients(
                Order::query()
                    ->whereBetween('created_at', [$start, $end])
                    ->whereIn('status', self::CONFIRMED_STATUSES)
                    ->where(fn ($q) => $q->whereNull('payment_session_id')
                        ->orWhere('payment_session_id', 'not like', 'cs_test_%'))
            ),
        ];
    }
}
