<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerOrganisation;
use App\Models\PartnerSale;
use App\Models\PartnerSaleAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Okelcor-side review of partner sales, and the books export.
 *
 * The export is what the brief is actually about — "it's always hard for me to
 * get data on book-related stuff". The partner app is only the intake.
 */
class AdminPartnerSaleController extends Controller
{
    /**
     * GET /api/v1/admin/partner-sales
     */
    public function index(Request $request): JsonResponse
    {
        $this->validateFilters($request);

        $query = $this->filteredQuery($request)
            ->with(['organisation:id,name,country', 'enteredBy:id,name', 'verifier:id,name'])
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        $paginated = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $paginated->getCollection()->map(fn ($s) => $this->format($s))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'markets'      => PartnerOrganisation::markets(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/partner-sales/totals
     *
     * Per market, per partner and per currency. Never a single combined
     * figure: there is no FX source covering NGN/GHS/KES/AED, so any blended
     * total would be an invented number in a bookkeeping tool.
     */
    public function totals(Request $request): JsonResponse
    {
        $this->validateFilters($request);

        $base = $this->filteredQuery($request);

        $byPartner = (clone $base)
            ->join('partner_organisations', 'partner_organisations.id', '=', 'partner_sales.partner_org_id')
            ->groupBy('partner_sales.partner_org_id', 'partner_organisations.name', 'partner_organisations.country', 'partner_sales.currency')
            ->selectRaw('partner_sales.partner_org_id, partner_organisations.name as partner_name, partner_organisations.country, partner_sales.currency,
                         COUNT(*) as entries, SUM(partner_sales.quantity) as pieces, SUM(partner_sales.total_amount) as amount')
            ->get();

        $byStatus = (clone $base)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as entries')
            ->pluck('entries', 'status');

        return response()->json([
            'data' => [
                'by_partner' => $byPartner->map(fn ($r) => [
                    'partner_org_id' => (int) $r->partner_org_id,
                    'partner_name'   => $r->partner_name,
                    'market'         => mb_strtolower(trim((string) $r->country)),
                    'currency'       => $r->currency,
                    'entries'        => (int) $r->entries,
                    'pieces'         => (int) $r->pieces,
                    'amount'         => number_format((float) $r->amount, 2, '.', ''),
                ])->values(),

                'by_market' => $byPartner
                    ->groupBy(fn ($r) => mb_strtolower(trim((string) $r->country)) . '|' . $r->currency)
                    ->map(function ($rows, $key) {
                        [$market, $currency] = explode('|', $key);

                        return [
                            'market'   => $market,
                            'currency' => $currency,
                            'entries'  => (int) $rows->sum('entries'),
                            'pieces'   => (int) $rows->sum('pieces'),
                            'amount'   => number_format((float) $rows->sum('amount'), 2, '.', ''),
                        ];
                    })->values(),

                'by_status' => $byStatus,
            ],
            'meta' => [
                'note' => 'Amounts are grouped by currency and are never converted or combined. '
                    . 'No exchange-rate source covering these markets is available; conversion is a '
                    . 'finance decision against a dated rate.',
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/partner-sales/{id}
     */
    public function show(int $id): JsonResponse
    {
        $sale = PartnerSale::withTrashed()
            ->with(['organisation:id,name,country', 'enteredBy:id,name', 'verifier:id,name', 'audits'])
            ->find($id);

        if (! $sale) {
            return response()->json(['message' => 'Sale not found.'], 404);
        }

        return response()->json([
            'data' => $this->format($sale) + [
                'audit_trail' => $sale->audits
                    ->sortByDesc('created_at')
                    ->map(fn (PartnerSaleAudit $a) => [
                        'action'     => $a->action,
                        'actor_type' => $a->actor_type,
                        'actor'      => $a->actor_label,
                        'changes'    => $a->changes,
                        'at'         => $a->created_at?->toIso8601String(),
                    ])->values(),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/partner-sales/{id}/verify
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        return $this->review($request, $id, 'verify');
    }

    /**
     * POST /api/v1/admin/partner-sales/{id}/dispute
     */
    public function dispute(Request $request, int $id): JsonResponse
    {
        return $this->review($request, $id, 'dispute');
    }

    /**
     * Shared body for verify/dispute. Not routed directly: a route closure
     * cannot be serialised by `artisan route:cache`, which the production
     * deploy runs on every release.
     */
    private function review(Request $request, int $id, string $action): JsonResponse
    {
        $sale = PartnerSale::find($id);

        if (! $sale) {
            return response()->json(['message' => 'Sale not found.'], 404);
        }

        $data = $request->validate([
            'note' => [$action === 'dispute' ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'note.required' => 'Say what is wrong with this entry — the partner will need to know.',
        ]);

        $admin = $request->user();

        DB::transaction(function () use ($sale, $action, $data, $admin, $request) {
            $sale->status      = $action === 'verify' ? 'verified' : 'disputed';
            $sale->verified_by = $admin?->id;
            $sale->verified_at = now();
            $sale->review_note = $data['note'] ?? null;
            $sale->save();

            PartnerSaleAudit::record(
                $sale->id,
                $action === 'verify' ? 'verified' : 'disputed',
                'admin_user',
                $admin?->id,
                $admin?->name,
                ['note' => $data['note'] ?? null],
                $request->ip(),
            );
        });

        return response()->json([
            'data'    => $this->format($sale->fresh(['organisation', 'enteredBy', 'verifier'])),
            'message' => $action === 'verify' ? 'Sale verified.' : 'Sale disputed.',
        ]);
    }

    /**
     * GET /api/v1/admin/partner-sales/export
     *
     * Streams real CSV, following OrderImportController::export().
     *
     * Deliberately NOT the paginated-JSON shape used by
     * AdminCustomerController::export() — 200 rows a page that the caller has
     * to stitch back together is not an export, and this is the one feature
     * the brief was actually asking for.
     *
     * Chunked so memory stays flat regardless of how many rows the filter
     * matches; a month of several markets is not large today, but a year-end
     * export with no date filter should not be the thing that discovers a
     * memory limit.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->validateFilters($request);

        $filename = 'okelcor_partner_sales_' . now()->format('Y-m-d_His') . '.csv';

        $query = $this->filteredQuery($request)
            ->with(['organisation:id,name,country', 'enteredBy:id,name', 'verifier:id,name'])
            ->orderBy('partner_sales.sold_at')
            ->orderBy('partner_sales.id');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'date sold',
                'partner',
                'market',
                'country',
                'entered by',
                'size',
                'brand',
                'type',
                'quantity',
                'unit price',
                'total amount',
                'currency',
                'customer',
                'notes',
                'status',
                'verified by',
                'verified at',
                'source',
                'catalogue product id',
                'entry reference',
                'recorded at',
            ]);

            $query->chunk(200, function ($sales) use ($handle) {
                foreach ($sales as $s) {
                    fputcsv($handle, [
                        $s->sold_at?->toDateString(),
                        $s->organisation?->name,
                        mb_strtolower(trim((string) $s->organisation?->country)),
                        $s->organisation?->country,
                        $s->enteredBy?->name,
                        $s->size,
                        $s->brand,
                        $s->tyre_type,
                        $s->quantity,
                        $s->unit_price,
                        $s->total_amount,
                        // Amount and currency travel together, with the date,
                        // because finance applies its own dated rate — nothing
                        // in this system converts.
                        $s->currency,
                        $s->customer_name,
                        $s->notes,
                        $s->status,
                        $s->verifier?->name,
                        $s->verified_at?->toDateTimeString(),
                        $s->source,
                        $s->product_id,
                        $s->client_generated_id,
                        $s->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'partner'  => ['nullable', 'integer', 'exists:partner_organisations,id'],
            'market'   => ['nullable', 'string', 'max:100'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d'],
            'status'   => ['nullable', Rule::in(PartnerSale::STATUSES)],
            'currency' => ['nullable', 'string', 'size:3'],
            'include_deleted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
    }

    private function filteredQuery(Request $request)
    {
        $query = PartnerSale::query()->from('partner_sales');

        // Soft-deleted entries are excluded by default but reachable, because
        // "a partner deleted this after we exported it" is exactly the kind of
        // question a books review needs to be able to answer.
        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        if ($request->filled('partner')) {
            $query->where('partner_sales.partner_org_id', $request->integer('partner'));
        }

        if ($request->filled('market')) {
            $market = mb_strtolower(trim($request->input('market')));
            $query->whereIn('partner_sales.partner_org_id', function ($sub) use ($market) {
                $sub->select('id')->from('partner_organisations')->whereRaw('LOWER(country) = ?', [$market]);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('partner_sales.sold_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('partner_sales.sold_at', '<=', $request->input('to'));
        }

        if ($request->filled('status')) {
            $query->where('partner_sales.status', $request->input('status'));
        }

        if ($request->filled('currency')) {
            $query->where('partner_sales.currency', strtoupper($request->input('currency')));
        }

        return $query;
    }

    private function format(PartnerSale $sale): array
    {
        return [
            'id'                  => $sale->id,
            'partner_org_id'      => $sale->partner_org_id,
            'partner_name'        => $sale->organisation?->name,
            'market'              => mb_strtolower(trim((string) $sale->organisation?->country)),
            'sold_at'             => $sale->sold_at?->toDateString(),
            'size'                => $sale->size,
            'brand'               => $sale->brand,
            'tyre_type'           => $sale->tyre_type,
            'product_id'          => $sale->product_id,
            'quantity'            => (int) $sale->quantity,
            'unit_price'          => (string) $sale->unit_price,
            'total_amount'        => (string) $sale->total_amount,
            'currency'            => $sale->currency,
            'customer_name'       => $sale->customer_name,
            'notes'               => $sale->notes,
            'status'              => $sale->status,
            'source'              => $sale->source,
            'entered_by'          => $sale->enteredBy?->name,
            'entered_by_user_id'  => $sale->entered_by_user_id,
            'verified_by'         => $sale->verifier?->name,
            'verified_at'         => $sale->verified_at?->toIso8601String(),
            'review_note'         => $sale->review_note,
            'client_generated_id' => $sale->client_generated_id,
            'deleted'             => $sale->trashed(),
            'created_at'          => $sale->created_at?->toIso8601String(),
        ];
    }
}
