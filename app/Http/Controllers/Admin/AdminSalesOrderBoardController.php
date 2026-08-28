<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesOrderEntry;
use App\Models\SalesOrderLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sales & Order Management board — finance's OT 3.html mockup. Hand-entered
 * orders with customer lines (revenue + tyres) against supplier lines
 * (costs + documents); gross profit, margin, the verification status and the
 * five KPI figures are all computed here from the lines, never stored.
 *
 * Reads are finance.view; writes are finance.manage.
 */
class AdminSalesOrderBoardController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/sales-orders — finance.view
    //
    // ?status=pending narrows to orders still owing supplier proof; ?period=
    // and ?segment= narrow the table AND the KPIs together, so the cards
    // always describe the rows below them.
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'  => ['nullable', 'in:all,pending'],
            'period'  => ['nullable', 'string', 'max:7'],
            'segment' => ['nullable', Rule::in(SalesOrderEntry::SEGMENTS)],
            'q'       => ['nullable', 'string', 'max:100'],
        ]);

        if (! SalesOrderEntry::available()) {
            return response()->json([
                'data'    => null,
                'meta'    => ['sales_orders_available' => false],
                'message' => 'The sales board is not available yet — the database migration has not run.',
            ]);
        }

        if (! empty($filters['period']) && ! SalesOrderEntry::isValidPeriod($filters['period'])) {
            return response()->json([
                'message' => "'{$filters['period']}' is not a month. Use YYYY-MM.",
                'errors'  => ['period' => ['Expected YYYY-MM.']],
            ], 422);
        }

        $query = SalesOrderEntry::with('lines')->orderByDesc('period')->orderBy('order_no');

        if (! empty($filters['period'])) {
            $query->where('period', $filters['period']);
        }

        if (! empty($filters['segment'])) {
            $query->where('segment', $filters['segment']);
        }

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(fn ($sub) => $sub->where('order_no', 'like', "%{$q}%")
                ->orWhere('customer_name', 'like', "%{$q}%"));
        }

        $entries = $query->get();

        $formatted = $entries->map(fn ($e) => $this->formatEntry($e));

        if (($filters['status'] ?? 'all') === 'pending') {
            $formatted = $formatted->filter(fn ($e) => $e['status'] === 'pending_proof')->values();
        }

        return response()->json([
            'data' => [
                // KPIs cover the filtered scope BEFORE the pending narrowing —
                // "pending" is a worklist view of the same population, and
                // recomputing the cards over only the laggards would make the
                // margins swing when the filter is clicked.
                'kpis'    => $this->kpis($entries),
                'entries' => $formatted,
            ],
            'meta' => [
                'sales_orders_available' => true,
                'segments'   => SalesOrderEntry::SEGMENTS,
                'categories' => SalesOrderEntry::CATEGORIES,
                'known_periods' => SalesOrderEntry::query()->distinct()->orderByDesc('period')->pluck('period'),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/sales-orders — finance.manage
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_no'      => ['required', 'string', 'max:50'],
            'customer_name' => ['required', 'string', 'max:150'],
            'segment'       => ['nullable', Rule::in(SalesOrderEntry::SEGMENTS)],
            'period'        => ['required', 'string', 'max:7'],
            'category'      => ['nullable', Rule::in(SalesOrderEntry::CATEGORIES)],
        ]);

        if (! SalesOrderEntry::isValidPeriod($data['period'])) {
            return response()->json([
                'message' => "'{$data['period']}' is not a month. Use YYYY-MM.",
                'errors'  => ['period' => ['Expected YYYY-MM.']],
            ], 422);
        }

        $data['order_no'] = trim($data['order_no']);

        $duplicate = SalesOrderEntry::where('order_no', $data['order_no'])->first();

        if ($duplicate !== null) {
            // The same real order twice would double the KPIs — a friendly
            // 422 carrying the existing entry so the UI can jump to it.
            return response()->json([
                'message' => "{$data['order_no']} is already on the board — add lines to the existing order.",
                'errors'  => ['order_no' => ['This order number is already entered.']],
                'data'    => $this->formatEntry($duplicate->load('lines')),
            ], 422);
        }

        $entry = SalesOrderEntry::create([
            'order_no'      => $data['order_no'],
            'customer_name' => $data['customer_name'],
            'segment'       => $data['segment'] ?? 'B2B',
            'period'        => $data['period'],
            'category'      => $data['category'] ?? 'Tyres',
            'created_by'    => $request->user()?->id,
        ]);

        // The mockup seeds every new order with its customer line — the
        // revenue side always exists, the amount just starts at zero.
        SalesOrderLine::create([
            'entry_id'   => $entry->id,
            'party_type' => SalesOrderLine::PARTY_CUSTOMER,
            'party_name' => $data['customer_name'],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data'    => $this->formatEntry($entry->fresh('lines')),
            'message' => 'Order added.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/sales-orders/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $entry = SalesOrderEntry::findOrFail($id);

        $data = $request->validate([
            'order_no'      => ['sometimes', 'string', 'max:50'],
            'customer_name' => ['sometimes', 'string', 'max:150'],
            'segment'       => ['sometimes', Rule::in(SalesOrderEntry::SEGMENTS)],
            'period'        => ['sometimes', 'string', 'max:7'],
            'category'      => ['sometimes', Rule::in(SalesOrderEntry::CATEGORIES)],
        ]);

        if (isset($data['period']) && ! SalesOrderEntry::isValidPeriod($data['period'])) {
            return response()->json([
                'message' => "'{$data['period']}' is not a month. Use YYYY-MM.",
                'errors'  => ['period' => ['Expected YYYY-MM.']],
            ], 422);
        }

        if (isset($data['order_no'])) {
            $data['order_no'] = trim($data['order_no']);

            $taken = SalesOrderEntry::where('order_no', $data['order_no'])
                ->where('id', '!=', $entry->id)->exists();

            if ($taken) {
                return response()->json([
                    'message' => "{$data['order_no']} is already on the board.",
                    'errors'  => ['order_no' => ['This order number is already entered.']],
                ], 422);
            }
        }

        $entry->update($data);

        return response()->json([
            'data'    => $this->formatEntry($entry->fresh('lines')),
            'message' => 'Order updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/sales-orders/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function destroy(int $id): JsonResponse
    {
        $entry = SalesOrderEntry::with('lines')->findOrFail($id);

        foreach ($entry->lines as $line) {
            if ($path = $line->getRawOriginal('file_path')) {
                Storage::disk('local')->delete($path);
            }
        }

        $entry->delete();

        return response()->json(['message' => 'Order removed.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/sales-orders/{id}/lines — finance.manage
    // -------------------------------------------------------------------------
    public function storeLine(Request $request, int $id): JsonResponse
    {
        $entry = SalesOrderEntry::findOrFail($id);

        $data = $request->validate([
            'party_type' => ['required', Rule::in(SalesOrderLine::PARTY_TYPES)],
            'party_name' => ['required', 'string', 'max:150'],
            'tyre_qty'   => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'amount'     => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'file'       => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $line = SalesOrderLine::create([
            'entry_id'   => $entry->id,
            'party_type' => $data['party_type'],
            'party_name' => $data['party_name'],
            // Tyre quantity is a revenue-side fact — a quantity on a supplier
            // line would double-count the same tyres into the KPIs.
            'tyre_qty'   => $data['party_type'] === SalesOrderLine::PARTY_CUSTOMER ? ($data['tyre_qty'] ?? 0) : 0,
            'amount'     => $data['amount'] ?? 0,
            'created_by' => $request->user()?->id,
        ]);

        $warning = null;

        if ($request->hasFile('file') && ! $this->storeFile($request->file('file'), $line)) {
            $warning = 'Line saved, but the document could not be stored. Attach it again.';
        }

        return response()->json([
            'data'    => $this->formatEntry($entry->fresh('lines')),
            'message' => $warning ?? 'Line added.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/sales-orders/lines/{lineId} — finance.manage
    // -------------------------------------------------------------------------
    public function updateLine(Request $request, int $lineId): JsonResponse
    {
        $line = SalesOrderLine::with('entry')->findOrFail($lineId);

        $data = $request->validate([
            'party_type' => ['sometimes', Rule::in(SalesOrderLine::PARTY_TYPES)],
            'party_name' => ['sometimes', 'string', 'max:150'],
            'tyre_qty'   => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'amount'     => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $line->fill($data);

        if ($line->party_type === SalesOrderLine::PARTY_SUPPLIER) {
            $line->tyre_qty = 0;
        }

        $line->save();

        return response()->json([
            'data'    => $this->formatEntry($line->entry->fresh('lines')),
            'message' => 'Line updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/sales-orders/lines/{lineId} — finance.manage
    // -------------------------------------------------------------------------
    public function destroyLine(int $lineId): JsonResponse
    {
        $line  = SalesOrderLine::with('entry')->findOrFail($lineId);
        $entry = $line->entry;

        if ($path = $line->getRawOriginal('file_path')) {
            Storage::disk('local')->delete($path);
        }

        $line->delete();

        return response()->json([
            'data'    => $this->formatEntry($entry->fresh('lines')),
            'message' => 'Line removed.',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/sales-orders/lines/{lineId}/file — finance.manage
    // -------------------------------------------------------------------------
    public function uploadLineFile(Request $request, int $lineId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $line = SalesOrderLine::with('entry')->findOrFail($lineId);

        if (! $this->storeFile($request->file('file'), $line)) {
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        return response()->json([
            'data'    => $this->formatEntry($line->entry->fresh('lines')),
            'message' => 'Document attached.',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/sales-orders/lines/{lineId}/download — finance.view
    // -------------------------------------------------------------------------
    public function downloadLineFile(int $lineId): BinaryFileResponse|JsonResponse
    {
        $line = SalesOrderLine::findOrFail($lineId);
        $path = $line->getRawOriginal('file_path');

        if (! $path) {
            return response()->json(['message' => 'No document is attached to this line.'], 404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            Log::warning('Sales board file missing on disk', ['line' => $lineId, 'path' => $path]);

            return response()->json(['message' => 'The attached document could not be found.'], 404);
        }

        return response()->download($disk->path($path), $line->original_filename ?: basename($path));
    }

    // -------------------------------------------------------------------------

    private function storeFile(\Illuminate\Http\UploadedFile $file, SalesOrderLine $line): bool
    {
        $safe = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_');
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = 'sales-orders/' . now()->format('Y') . '/'
            . now()->format('YmdHis') . "_line{$line->id}_" . $safe . '.' . $ext;

        try {
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('Sales board file could not be stored', ['line' => $line->id, 'error' => $e->getMessage()]);

            return false;
        }

        if ($previous = $line->getRawOriginal('file_path')) {
            Storage::disk('local')->delete($previous);
        }

        $line->update([
            'file_path'         => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getClientMimeType(),
            'file_size'         => $file->getSize(),
            'uploaded_at'       => now(),
        ]);

        return true;
    }

    /**
     * The mockup's arithmetic, verbatim: revenue and tyres from customer
     * lines, costs from supplier lines, and an order is verified exactly
     * when it HAS supplier lines and every one carries its document —
     * revenue with no recorded cost is not a verified margin, it is a
     * missing cost.
     *
     * @return array<string, mixed>
     */
    private function metrics(SalesOrderEntry $entry): array
    {
        $customer = $entry->lines->where('party_type', SalesOrderLine::PARTY_CUSTOMER);
        $supplier = $entry->lines->where('party_type', SalesOrderLine::PARTY_SUPPLIER);

        $revenue = round((float) $customer->sum('amount'), 2);
        $costs   = round((float) $supplier->sum('amount'), 2);
        $gp      = round($revenue - $costs, 2);

        $missingDoc = $supplier->contains(fn (SalesOrderLine $l) => ! $l->hasFile());

        return [
            'revenue'  => $revenue,
            'costs'    => $costs,
            'gp'       => $gp,
            'margin'   => $revenue > 0 ? round(($gp / $revenue) * 100, 2) : null,
            'tyres'    => (int) $customer->sum('tyre_qty'),
            'status'   => ($supplier->isNotEmpty() && ! $missingDoc) ? 'verified' : 'pending_proof',
        ];
    }

    /**
     * The five cards, over whatever scope the filters selected.
     *
     * @param  Collection<int, SalesOrderEntry>  $entries
     * @return array<string, mixed>
     */
    private function kpis(Collection $entries): array
    {
        $customers = collect();
        $tyres = 0;
        $tyreRevenue = 0.0;
        $bySegment = ['B2B' => ['revenue' => 0.0, 'costs' => 0.0], 'B2C' => ['revenue' => 0.0, 'costs' => 0.0]];

        foreach ($entries as $entry) {
            if (trim($entry->customer_name) !== '') {
                $customers->push(mb_strtolower(trim($entry->customer_name)));
            }

            $m = $this->metrics($entry);

            $tyres       += $m['tyres'];
            $tyreRevenue += $m['revenue'];

            if (isset($bySegment[$entry->segment])) {
                $bySegment[$entry->segment]['revenue'] += $m['revenue'];
                $bySegment[$entry->segment]['costs']   += $m['costs'];
            }
        }

        $marginOf = function (array $seg): ?float {
            return $seg['revenue'] > 0
                ? round((($seg['revenue'] - $seg['costs']) / $seg['revenue']) * 100, 1)
                : null;
        };

        return [
            'unique_customers'  => $customers->unique()->count(),
            'tyres_sold'        => $tyres,
            // Null, not zero, when no tyres — an average over nothing is
            // undefined, and €0.00 reads as a fact.
            'avg_price_per_tyre' => $tyres > 0 ? round($tyreRevenue / $tyres, 2) : null,
            'b2b_margin_percent' => $marginOf($bySegment['B2B']),
            'b2c_margin_percent' => $marginOf($bySegment['B2C']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(SalesOrderEntry $entry): array
    {
        $m = $this->metrics($entry);

        return [
            'id'            => $entry->id,
            'order_no'      => $entry->order_no,
            'customer_name' => $entry->customer_name,
            'segment'       => $entry->segment,
            'period'        => $entry->period,
            'category'      => $entry->category,
            'status'        => $m['status'],
            'revenue'       => $m['revenue'],
            'costs'         => $m['costs'],
            'gross_profit'  => $m['gp'],
            'margin_percent' => $m['margin'],
            'tyres'         => $m['tyres'],
            'lines'         => $entry->lines->map(fn (SalesOrderLine $l) => [
                'id'         => $l->id,
                'party_type' => $l->party_type,
                'party_name' => $l->party_name,
                'tyre_qty'   => $l->tyre_qty,
                'amount'     => (float) $l->amount,
                'has_file'   => $l->hasFile(),
                'file_name'  => $l->original_filename,
            ])->values(),
        ];
    }
}
