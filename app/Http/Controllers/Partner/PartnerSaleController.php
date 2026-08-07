<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Partner\StorePartnerSaleRequest;
use App\Http\Requests\Partner\UpdatePartnerSaleRequest;
use App\Models\PartnerSale;
use App\Models\PartnerSaleAudit;
use App\Models\PartnerUser;
use App\Models\Product;
use App\Services\PartnerSaleProductMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Partner sale intake.
 *
 * ── The idempotency contract ──────────────────────────────────────────────
 *
 * Every entry carries a `client_generated_id` minted on the device before it
 * is ever sent. The client REUSES that id for edits: correcting an entry flips
 * its sync state back to pending and re-pushes the same id with new values. So
 * "same id, different payload" is a legitimate edit, not a replay, and it
 * cannot be routed to PATCH instead — an entry created and corrected while
 * still offline has no server id yet.
 *
 * Agreed behaviour for POST with an existing `client_generated_id`:
 *
 *   Within the edit window   → apply the update, 200 with the row
 *   Outside the edit window  → 200 with the existing row, payload ignored
 *   Soft-deleted             → 200 with the existing row, NOT resurrected
 *   Never                    → 409
 *
 * 409 is specifically excluded because it tells the device its send failed, so
 * the outbox either retries forever or drops the entry — the corruption the
 * mechanism exists to prevent, arriving through the other door.
 *
 * A cross-partner id cannot arise here: uniqueness is scoped to
 * (partner_org_id, client_generated_id) and every lookup below is scoped to
 * the caller's organisation, so an id that exists under a different partner
 * is simply not visible and a fresh row is created. The agreed "404, never
 * 403" rule applies to the id-addressed routes (PATCH/DELETE), where it is
 * enforced — see findOwnedSale().
 *
 * `client_revision` is an OPTIONAL monotonic counter. When the client sends
 * it, a lower-or-equal revision will not overwrite a higher one, so a retry of
 * v1 arriving after v2 has synced cannot silently revert the correction. When
 * absent, behaviour is exactly the agreed table above.
 */
class PartnerSaleController extends Controller
{
    /**
     * POST /api/v1/partner/sales
     */
    public function store(StorePartnerSaleRequest $request): JsonResponse
    {
        /** @var PartnerUser $user */
        $user = $request->user();

        $data = $request->validated();

        $existing = PartnerSale::withTrashed()
            ->where('partner_org_id', $user->partner_org_id)
            ->where('client_generated_id', $data['client_generated_id'])
            ->first();

        if ($existing) {
            return $this->resolveExisting($request, $user, $existing, $data);
        }

        $sale = DB::transaction(function () use ($user, $data, $request) {
            $sale = new PartnerSale();

            $sale->fill($this->attributesFrom($data));
            $sale->total_amount        = PartnerSale::computeTotal((int) $data['quantity'], $data['unit_price']);
            $sale->partner_org_id      = $user->partner_org_id;
            $sale->entered_by_user_id  = $user->id;
            $sale->client_generated_id = $data['client_generated_id'];
            $sale->client_revision     = (int) ($data['client_revision'] ?? 1);
            $sale->source              = 'app';
            $sale->status              = 'submitted';
            $sale->product_id          = PartnerSaleProductMatcher::match(
                $data['size'],
                $data['brand'] ?? null,
            );
            $sale->save();

            PartnerSaleAudit::record(
                $sale->id,
                'created',
                'partner_user',
                $user->id,
                $user->name,
                null,
                $request->ip(),
            );

            return $sale;
        });

        return response()->json([
            'data'    => $this->format($sale),
            'meta'    => ['idempotency' => 'created'],
            'message' => 'Sale recorded.',
        ], 201);
    }

    /**
     * GET /api/v1/partner/sales?from=&to=&per_page=
     *
     * Scoped to the ORGANISATION, not the individual — a distributor's staff
     * report into a shared book and need to see it.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var PartnerUser $user */
        $user = $request->user();

        $request->validate([
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d'],
            'status'   => ['nullable', 'in:submitted,verified,disputed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = PartnerSale::with('enteredBy:id,name')
            ->where('partner_org_id', $user->partner_org_id)
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $request->input('to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginated = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $paginated->getCollection()->map(fn ($s) => $this->format($s))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/partner/sales/{id}
     */
    public function update(UpdatePartnerSaleRequest $request, int $id): JsonResponse
    {
        /** @var PartnerUser $user */
        $user = $request->user();

        $sale = $this->findOwnedSale($user, $id);

        if (! $sale) {
            return $this->notFound();
        }

        if (! $sale->isWithinEditWindow()) {
            return $this->editWindowClosed($sale);
        }

        $data = $request->validated();

        // Same stale-revision guard as the POST path, so an out-of-order
        // delivery cannot revert a newer correction on either route.
        if ($this->isStaleRevision($sale, $data)) {
            return response()->json([
                'data'    => $this->format($sale),
                'meta'    => ['idempotency' => 'unchanged_stale_revision'],
                'message' => 'A newer version of this entry is already saved.',
            ]);
        }

        $changes = $this->applyUpdate($sale, $data, $user, $request->ip());

        return response()->json([
            'data'    => $this->format($sale),
            'meta'    => ['idempotency' => $changes ? 'updated' : 'unchanged'],
            'message' => $changes ? 'Sale updated.' : 'No changes.',
        ]);
    }

    /**
     * DELETE /api/v1/partner/sales/{id}
     *
     * Soft delete. A sale that may already have been exported into the books
     * must remain in the audit trail rather than disappearing because someone
     * tapped delete on a phone.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var PartnerUser $user */
        $user = $request->user();

        $sale = $this->findOwnedSale($user, $id);

        if (! $sale) {
            return $this->notFound();
        }

        if (! $sale->isWithinEditWindow()) {
            return $this->editWindowClosed($sale);
        }

        DB::transaction(function () use ($sale, $user, $request) {
            PartnerSaleAudit::record(
                $sale->id,
                'deleted',
                'partner_user',
                $user->id,
                $user->name,
                null,
                $request->ip(),
            );

            $sale->delete();
        });

        return response()->json(['message' => 'Sale removed.']);
    }

    /**
     * GET /api/v1/partner/summary?period=week|month
     *
     * Totals are grouped BY CURRENCY and never summed across them — there is
     * no FX source covering these markets, and one blended number would be
     * meaningless at best and wrong in the books at worst.
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var PartnerUser $user */
        $user = $request->user();

        $request->validate([
            'period' => ['nullable', 'in:week,month'],
        ]);

        $period = $request->input('period', 'month');
        $start  = $period === 'week' ? now()->startOfWeek() : now()->startOfMonth();
        $end    = $period === 'week' ? now()->endOfWeek() : now()->endOfMonth();

        $rows = PartnerSale::query()
            ->where('partner_org_id', $user->partner_org_id)
            ->whereBetween('sold_at', [$start->toDateString(), $end->toDateString()])
            ->groupBy('currency')
            ->selectRaw('currency, COUNT(*) as entries, SUM(quantity) as pieces, SUM(total_amount) as amount')
            ->get();

        return response()->json([
            'data' => [
                'period' => $period,
                'from'   => $start->toDateString(),
                'to'     => $end->toDateString(),
                'totals' => $rows->map(fn ($r) => [
                    'currency' => $r->currency,
                    'entries'  => (int) $r->entries,
                    'pieces'   => (int) $r->pieces,
                    'amount'   => number_format((float) $r->amount, 2, '.', ''),
                ])->values(),
            ],
            'meta' => [
                // Stated explicitly so no consumer is tempted to add these up.
                'note' => 'Totals are per currency and are never converted or combined.',
            ],
        ]);
    }

    /**
     * GET /api/v1/partner/sizes
     *
     * Distinct catalogue sizes for the entry form's autocomplete. Deliberately
     * a separate endpoint rather than the product list — the catalogue is in
     * the thousands of rows and this must be loadable on a mid-range phone on
     * a bad connection.
     */
    public function sizes(Request $request): JsonResponse
    {
        $sizes = Product::query()
            ->whereNotNull('width')
            ->whereNotNull('height')
            ->whereNotNull('rim')
            ->select('width', 'height', 'rim')
            ->distinct()
            ->orderBy('width')
            ->orderBy('height')
            ->orderBy('rim')
            ->get()
            ->map(fn ($p) => [
                'label'  => "{$p->width}/{$p->height} R{$p->rim}",
                'width'  => $p->width,
                'height' => $p->height,
                'rim'    => $p->rim,
            ])
            ->values();

        $brands = Product::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->values();

        return response()->json([
            'data' => [
                'sizes'      => $sizes,
                'brands'     => $brands,
                'tyre_types' => PartnerSale::TYRE_TYPES,
                'currencies' => array_values(config('partner.currencies', [])),
            ],
            'meta' => [
                // The form accepts free text regardless; this is autocomplete,
                // not a constraint. Partners sell tyres we do not list.
                'free_text_allowed' => true,
            ],
        ]);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * The POST-with-existing-id path. See the class docblock for the contract.
     */
    private function resolveExisting(
        Request $request,
        PartnerUser $user,
        PartnerSale $existing,
        array $data,
    ): JsonResponse {
        // Deleted entries are returned as-is, never resurrected. Without this
        // a device that was offline when the entry was deleted would recreate
        // it on the next flush — and the partner would have no way to tell.
        if ($existing->trashed()) {
            return response()->json([
                'data'    => $this->format($existing),
                'meta'    => ['idempotency' => 'unchanged_deleted'],
                'message' => 'This entry was removed.',
            ]);
        }

        if (! $existing->isWithinEditWindow()) {
            return response()->json([
                'data'    => $this->format($existing),
                'meta'    => ['idempotency' => 'unchanged_locked'],
                'message' => 'This entry can no longer be changed. Contact Okelcor to correct it.',
            ]);
        }

        if ($this->isStaleRevision($existing, $data)) {
            return response()->json([
                'data'    => $this->format($existing),
                'meta'    => ['idempotency' => 'unchanged_stale_revision'],
                'message' => 'A newer version of this entry is already saved.',
            ]);
        }

        $changes = $this->applyUpdate($existing, $data, $user, $request->ip());

        return response()->json([
            'data'    => $this->format($existing),
            'meta'    => ['idempotency' => $changes ? 'updated' : 'unchanged'],
            'message' => $changes ? 'Sale updated.' : 'Already saved.',
        ]);
    }

    /**
     * True when the client sent a revision that is not newer than what is
     * stored — i.e. this is an old version arriving late.
     *
     * Absent `client_revision`, this is always false and the agreed
     * last-write-wins-within-the-window behaviour applies unchanged.
     */
    private function isStaleRevision(PartnerSale $sale, array $data): bool
    {
        if (! array_key_exists('client_revision', $data) || $data['client_revision'] === null) {
            return false;
        }

        return (int) $data['client_revision'] <= (int) $sale->client_revision;
    }

    /**
     * Apply changed fields, write an audit row, return the diff.
     *
     * @return array<string, array{from: mixed, to: mixed}> fields that moved
     */
    private function applyUpdate(PartnerSale $sale, array $data, PartnerUser $user, ?string $ip): array
    {
        // Only fields the client actually sent — an absent key must not blank
        // a stored value on a PATCH.
        $attributes = $this->attributesFrom($data);

        $changes = [];

        foreach ($attributes as $field => $value) {
            $current = $sale->getAttribute($field);

            $currentComparable = $current instanceof \DateTimeInterface
                ? $current->format('Y-m-d')
                : (string) $current;

            // Numbers are compared numerically, not as strings. The decimal
            // cast returns "250.00" while a client that sent 250.0 stringifies
            // to "250" — comparing those as text reports a change on every
            // identical replay, which would turn each retry of an unchanged
            // entry into a spurious audit row and an "updated" response.
            $unchanged = is_numeric($currentComparable) && is_numeric($value)
                ? (float) $currentComparable === (float) $value
                : $currentComparable === (string) $value;

            if (! $unchanged) {
                $changes[$field] = ['from' => $currentComparable, 'to' => (string) $value];
                $sale->setAttribute($field, $value);
            }
        }

        // Total is always re-derived from whatever quantity and unit price now
        // stand — including on a PATCH that sent only one of the two, where
        // trusting the stored total would leave the line disagreeing with itself.
        $recomputedTotal = PartnerSale::computeTotal((int) $sale->quantity, $sale->unit_price);

        if ((string) $sale->total_amount !== $recomputedTotal) {
            $changes['total_amount'] = ['from' => (string) $sale->total_amount, 'to' => $recomputedTotal];
            $sale->total_amount      = $recomputedTotal;
        }

        if ($changes === []) {
            // Still record the revision bump if one arrived, so a later stale
            // retry is still recognised as stale.
            if (isset($data['client_revision']) && (int) $data['client_revision'] > (int) $sale->client_revision) {
                $sale->client_revision = (int) $data['client_revision'];
                $sale->save();
            }

            return [];
        }

        DB::transaction(function () use ($sale, $data, $changes, $user, $ip) {
            if (isset($data['client_revision'])) {
                $sale->client_revision = (int) $data['client_revision'];
            }

            // Re-match the catalogue when size or brand moved; a corrected size
            // that still points at the old SKU would be worse than no link.
            if (isset($changes['size']) || isset($changes['brand'])) {
                $sale->product_id = PartnerSaleProductMatcher::match($sale->size, $sale->brand);
            }

            $sale->save();

            PartnerSaleAudit::record(
                $sale->id,
                'updated',
                'partner_user',
                $user->id,
                $user->name,
                $changes,
                $ip,
            );
        });

        return $changes;
    }

    /**
     * Map validated input to model attributes, computing the total server-side.
     */
    private function attributesFrom(array $data): array
    {
        $attributes = [
            'sold_at'       => $data['sold_at']       ?? null,
            'size'          => $data['size']          ?? null,
            'brand'         => $data['brand']         ?? null,
            'tyre_type'     => $data['tyre_type']     ?? null,
            'quantity'      => $data['quantity']      ?? null,
            'unit_price'    => $data['unit_price']    ?? null,
            'currency'      => $data['currency']      ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'notes'         => $data['notes']         ?? null,
        ];

        return array_filter($attributes, fn ($v, $k) => array_key_exists($k, $data), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Look up a sale by id, scoped to the caller's organisation.
     *
     * Returns null both when the id does not exist and when it belongs to a
     * different partner — the caller turns both into a 404. Deliberately not
     * 403: a 403 would confirm the id exists, letting one partner probe for
     * another's entries.
     */
    private function findOwnedSale(PartnerUser $user, int $id): ?PartnerSale
    {
        return PartnerSale::where('partner_org_id', $user->partner_org_id)
            ->where('id', $id)
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Entry not found.',
            'code'    => 'not_found',
        ], 404);
    }

    private function editWindowClosed(PartnerSale $sale): JsonResponse
    {
        return response()->json([
            'data'    => $this->format($sale),
            'message' => 'This entry can no longer be changed. Contact Okelcor to correct it.',
            'code'    => 'edit_window_closed',
        ], 422);
    }

    private function format(PartnerSale $sale): array
    {
        return [
            'id'                  => $sale->id,
            'client_generated_id' => $sale->client_generated_id,
            'client_revision'     => (int) $sale->client_revision,
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
            'editable'            => ! $sale->trashed() && $sale->isWithinEditWindow(),
            'deleted'             => $sale->trashed(),
            'created_at'          => $sale->created_at?->toIso8601String(),
            'updated_at'          => $sale->updated_at?->toIso8601String(),
        ];
    }
}
