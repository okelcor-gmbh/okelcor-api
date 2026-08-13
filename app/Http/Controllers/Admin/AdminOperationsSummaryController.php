<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceInvoice;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\OperationsSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The operations board, and the invoice reconciliation behind its two invoice
 * columns.
 */
class AdminOperationsSummaryController extends Controller
{
    public function __construct(private OperationsSummaryService $summary)
    {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/operations/summary?from=&to= — orders.view
    //
    // One row per channel, plus a total. Chart-ready and self-describing: the
    // definitions ride along so the board can say what each column means where
    // it is read, rather than in a document nobody has open.
    // -------------------------------------------------------------------------
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $data = $this->summary->build($request->input('from'), $request->input('to'));

        return response()->json([
            'data' => $data,
            'meta' => [
                // Between the code deploying and the migration running, the
                // finance column is a structural zero rather than a real one.
                // Saying so is the difference between "finance is behind" and
                // "this is not switched on yet".
                'finance_recording_available' => Schema::hasTable('finance_invoices'),
                'channels'                    => Order::CHANNELS,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/operations/invoice-reconciliation?from=&to=&channel=
    //   — finance.view
    //
    // The two counts are the headline; this is what you open when they differ.
    // Both sides of the mismatch, named, so the answer is "sevDesk 2026-114 has
    // no invoice here" rather than "there is a discrepancy of one".
    // -------------------------------------------------------------------------
    public function reconciliation(Request $request): JsonResponse
    {
        $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
            'channel' => ['nullable', 'in:normal,ebay,all'],
        ]);

        if (! Schema::hasTable('finance_invoices')) {
            return response()->json([
                'data' => ['available' => false, 'matched' => [], 'only_here' => [], 'only_in_finance' => []],
                'meta' => ['reason' => 'Finance invoice recording is not switched on yet.'],
                'message' => 'success',
            ]);
        }

        $end   = $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : CarbonImmutable::today();
        $start = $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : $end->startOfMonth();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $channel = $request->input('channel', 'all');

        $ebayRefs = Order::query()->channel(Order::CHANNEL_EBAY)->pluck('ref')->all();

        $ours = Invoice::query()
            ->whereBetween('issued_at', [$start->startOfDay(), $end->endOfDay()])
            ->when($channel === 'ebay', fn ($q) => $q->whereIn('order_ref', $ebayRefs ?: ['']))
            ->when($channel === 'normal' && $ebayRefs !== [], fn ($q) => $q->whereNotIn('order_ref', $ebayRefs))
            ->get(['id', 'invoice_number', 'order_ref', 'amount', 'issued_at']);

        $theirs = FinanceInvoice::query()
            ->whereDate('issued_on', '>=', $start->toDateString())
            ->whereDate('issued_on', '<=', $end->toDateString())
            ->when($channel !== 'all', fn ($q) => $q->where('channel', $channel))
            ->get();

        // Matched on order_ref first and our invoice number second. Either is a
        // deliberate act by whoever typed the finance row; matching on amount
        // would pair two unrelated invoices that happen to be for the same
        // figure, which is common in this business and would hide a real gap.
        $theirsByRef    = $theirs->whereNotNull('order_ref')->keyBy(fn ($f) => strtoupper((string) $f->order_ref));
        $theirsByNumber = $theirs->whereNotNull('invoice_number')->keyBy(fn ($f) => strtoupper((string) $f->invoice_number));

        $matched  = [];
        $onlyHere = [];
        $usedIds  = [];

        foreach ($ours as $invoice) {
            $match = $theirsByRef[strtoupper((string) $invoice->order_ref)]
                ?? $theirsByNumber[strtoupper((string) $invoice->invoice_number)]
                ?? null;

            if ($match === null) {
                $onlyHere[] = $this->formatOurs($invoice);
                continue;
            }

            $usedIds[$match->id] = true;

            $matched[] = [
                'order_ref'          => $invoice->order_ref,
                'our_invoice'        => $invoice->invoice_number,
                'finance_invoice'    => $match->external_number,
                'our_amount'         => (float) $invoice->amount,
                'finance_amount'     => $match->amount === null ? null : (float) $match->amount,
                // Two systems holding the same invoice at different money is a
                // worse finding than one holding it alone, and is invisible
                // from the counts on the board.
                'amount_matches'     => $match->amount === null
                    ? null
                    : round((float) $invoice->amount, 2) === round((float) $match->amount, 2),
            ];
        }

        $onlyInFinance = $theirs
            ->reject(fn ($f) => isset($usedIds[$f->id]))
            ->map(fn (FinanceInvoice $f) => [
                'id'              => $f->id,
                'system'          => $f->system,
                'external_number' => $f->external_number,
                'order_ref'       => $f->order_ref,
                'amount'          => $f->amount === null ? null : (float) $f->amount,
                'currency'        => $f->currency,
                'issued_on'       => $f->issued_on?->toDateString(),
                'channel'         => $f->channel,
                'notes'           => $f->notes,
                // Named because it is the commonest real cause: finance booked
                // an invoice against an order reference this system has never
                // heard of.
                'order_known_here' => $f->order_ref !== null
                    && Order::where('ref', $f->order_ref)->exists(),
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'available'       => true,
                'period'          => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
                'channel'         => $channel,
                'counts'          => [
                    'website_invoices' => $ours->count(),
                    'finance_invoices' => $theirs->count(),
                    'matched'          => count($matched),
                    'only_here'        => count($onlyHere),
                    'only_in_finance'  => count($onlyInFinance),
                    'amount_mismatch'  => count(array_filter($matched, fn ($m) => $m['amount_matches'] === false)),
                ],
                'matched'         => $matched,
                'only_here'       => $onlyHere,
                'only_in_finance' => $onlyInFinance,
            ],
            'message' => 'success',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOurs(Invoice $invoice): array
    {
        return [
            'id'             => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'order_ref'      => $invoice->order_ref,
            'amount'         => (float) $invoice->amount,
            'issued_at'      => $invoice->issued_at?->toIso8601String(),
        ];
    }
}
