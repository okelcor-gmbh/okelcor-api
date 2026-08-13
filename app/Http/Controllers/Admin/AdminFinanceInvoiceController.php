<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceInvoice;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Invoices as sevDesk has them, typed in by finance.
 *
 * Not an integration, deliberately — see the migration. Finance enters what
 * they raised, and the board compares the count against the invoices this
 * system produced.
 */
class AdminFinanceInvoiceController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance-invoices — finance.view
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
            'channel' => ['nullable', Rule::in(FinanceInvoice::CHANNELS)],
            'system'  => ['nullable', 'string', 'max:30'],
            'matched' => ['nullable', 'in:yes,no'],
            'q'       => ['nullable', 'string', 'max:100'],
        ]);

        $query = FinanceInvoice::query()->with('recordedBy:id,name')->orderByDesc('issued_on')->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('issued_on', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('issued_on', '<=', $request->input('to'));
        }

        foreach (['channel', 'system'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(fn ($sub) => $sub->where('external_number', 'like', "%{$q}%")
                ->orWhere('order_ref', 'like', "%{$q}%")
                ->orWhere('invoice_number', 'like', "%{$q}%"));
        }

        // "Matched" means this system knows the order it names — which is the
        // filter finance actually wants, because the unmatched ones are the
        // work.
        if ($request->filled('matched')) {
            $refs = Order::query()->pluck('ref')->all();

            $request->input('matched') === 'yes'
                ? $query->whereIn('order_ref', $refs ?: [''])
                : $query->where(fn ($sub) => $sub->whereNull('order_ref')->orWhereNotIn('order_ref', $refs ?: ['']));
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($f) => $this->format($f))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'systems'      => FinanceInvoice::SYSTEMS,
                'channels'     => FinanceInvoice::CHANNELS,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/finance-invoices — finance.manage
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'system'          => ['nullable', Rule::in(FinanceInvoice::SYSTEMS)],
            'external_number' => ['required', 'string', 'max:60'],
            'order_ref'       => ['nullable', 'string', 'max:30'],
            'invoice_number'  => ['nullable', 'string', 'max:50'],
            'amount'          => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'issued_on'       => ['required', 'date'],
            'channel'         => ['nullable', Rule::in(FinanceInvoice::CHANNELS)],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $data['system'] ??= 'sevdesk';

        $duplicate = FinanceInvoice::where('system', $data['system'])
            ->where('external_number', $data['external_number'])
            ->first();

        if ($duplicate !== null) {
            // A friendly 422 rather than a database error: entering the same
            // invoice twice would make the two sides of the board agree when
            // they do not, which is the exact failure this board exists to
            // catch.
            return response()->json([
                'message' => "Invoice {$data['external_number']} is already recorded (entered "
                    . ($duplicate->created_at?->toDateString() ?? 'earlier') . ').',
                'errors'  => ['external_number' => ['This invoice number has already been entered.']],
                'data'    => $this->format($duplicate),
            ], 422);
        }

        $data['channel']  ??= $this->inferChannel($data['order_ref'] ?? null);
        $data['currency']   = strtoupper($data['currency'] ?? 'EUR');
        $data['recorded_by'] = $request->user()->id;

        $invoice = FinanceInvoice::create($data);

        return response()->json([
            'data'    => $this->format($invoice->fresh('recordedBy')),
            'message' => 'Finance invoice recorded.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/finance-invoices/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = FinanceInvoice::findOrFail($id);

        $data = $request->validate([
            'external_number' => ['sometimes', 'string', 'max:60',
                Rule::unique('finance_invoices')->where('system', $invoice->system)->ignore($invoice->id)],
            'order_ref'      => ['sometimes', 'nullable', 'string', 'max:30'],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'amount'         => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency'       => ['sometimes', 'string', 'size:3'],
            'issued_on'      => ['sometimes', 'date'],
            'channel'        => ['sometimes', Rule::in(FinanceInvoice::CHANNELS)],
            'notes'          => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $invoice->update($data);

        return response()->json([
            'data'    => $this->format($invoice->fresh('recordedBy')),
            'message' => 'Finance invoice updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/finance-invoices/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function destroy(int $id): JsonResponse
    {
        FinanceInvoice::findOrFail($id)->delete();

        return response()->json(['message' => 'Finance invoice removed.']);
    }

    // -------------------------------------------------------------------------

    /**
     * An entry naming an eBay order is an eBay invoice; everything else is
     * normal. Only a default — the field is editable, because the row worth
     * recording most is the one naming an order this system does not have.
     */
    private function inferChannel(?string $orderRef): string
    {
        if ($orderRef === null || $orderRef === '') {
            return Order::CHANNEL_NORMAL;
        }

        return Order::where('ref', $orderRef)->value('source') === 'ebay'
            ? Order::CHANNEL_EBAY
            : Order::CHANNEL_NORMAL;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(FinanceInvoice $f): array
    {
        return [
            'id'              => $f->id,
            'system'          => $f->system,
            'external_number' => $f->external_number,
            'order_ref'       => $f->order_ref,
            'invoice_number'  => $f->invoice_number,
            'amount'          => $f->amount === null ? null : (float) $f->amount,
            'currency'        => $f->currency,
            'issued_on'       => $f->issued_on?->toDateString(),
            'channel'         => $f->channel,
            'notes'           => $f->notes,
            'recorded_by'     => $f->recordedBy?->name,
            'recorded_at'     => $f->created_at?->toIso8601String(),
            'order_known_here' => $f->order_ref !== null && $f->order_ref !== ''
                && Order::where('ref', $f->order_ref)->exists(),
        ];
    }
}
