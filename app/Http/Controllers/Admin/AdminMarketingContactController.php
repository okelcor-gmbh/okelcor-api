<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingContact;
use App\Models\MarketingContactMarket;
use App\Services\MarketingContactImportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Marketing contacts, segmented by market.
 *
 * A contact can belong to SEVERAL markets at once (`marketing_contact_markets`).
 * `marketing_contacts.market` is still present and still returned as `market` —
 * it is the contact's *primary* market, kept in sync with its memberships — so
 * nothing that reads the old single value breaks. New clients should prefer the
 * `markets` array.
 */
class AdminMarketingContactController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/marketing-contacts/markets — marketing.manage
    //
    // Auto-discovered from actual data, not a hardcoded list — a new market
    // shows up here the moment the first contact is imported/added under it,
    // with no backend change needed. Powers the market picker/tag UI.
    //
    // Counts are distinct contacts per market, so a contact in two markets is
    // counted once under each — the sum can legitimately exceed the total
    // contact count now that membership is many-to-many.
    // -------------------------------------------------------------------------
    public function markets(): JsonResponse
    {
        if (! MarketingContact::supportsMultipleMarkets()) {
            $rows = MarketingContact::query()
                ->whereNotNull('market')
                ->selectRaw('market, COUNT(*) as contact_count')
                ->groupBy('market')
                ->orderBy('market')
                ->get();

            return response()->json([
                'data' => $rows->map(fn ($r) => ['market' => $r->market, 'contact_count' => (int) $r->contact_count])->values(),
            ]);
        }

        $rows = MarketingContactMarket::query()
            ->selectRaw('market, COUNT(DISTINCT contact_id) as contact_count')
            ->groupBy('market')
            ->orderBy('market')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => ['market' => $r->market, 'contact_count' => (int) $r->contact_count])->values(),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/marketing-contacts/import — marketing.manage
    // -------------------------------------------------------------------------
    public function import(Request $request, MarketingContactImportService $service): JsonResponse
    {
        $data = $request->validate([
            'file'   => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'market' => ['required', 'string', 'max:50'],
        ]);

        set_time_limit(300);

        $path = $request->file('file')->getRealPath();

        try {
            $result = $service->import($path, $this->normalizeMarket($data['market']));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data'    => $result,
            'message' => "{$result['imported']} contacts imported, {$result['updated']} updated.",
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/marketing-contacts — marketing.manage
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['nullable', 'in:subscribed,unsubscribed,unknown'],
            'market'   => ['nullable', 'string', 'max:50'],
            'company'  => ['nullable', 'string', 'max:150'],
            'country'  => ['nullable', 'string', 'max:100'],
            'search'   => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = MarketingContact::query()->orderByDesc('created_at');

        if (MarketingContact::supportsMultipleMarkets()) {
            $query->with('marketMemberships');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('market')) {
            // Matches ANY of the contact's markets, not just the primary one —
            // otherwise a contact added to `germany` alongside `test` would be
            // missing from the germany list.
            $market = $this->normalizeMarket($request->market);

            if (MarketingContact::supportsMultipleMarkets()) {
                $query->whereHas('marketMemberships', fn ($q) => $q->where('market', $market));
            } else {
                $query->where('market', $market);
            }
        }
        if ($request->filled('company')) {
            $query->where('company', 'like', '%' . $request->company . '%');
        }
        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }
        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                  ->orWhere('first_name', 'like', $term)
                  ->orWhere('last_name', 'like', $term)
                  ->orWhere('company', 'like', $term);
            });
        }

        $perPage   = $request->integer('per_page', 50);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn ($c) => $this->formatContact($c))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/marketing-contacts — marketing.manage
    //
    // Manual single-contact add, scoped to one or more markets — the marketing
    // team can add one lead they picked up by hand without needing a whole CSV
    // import for it, and without needing anyone on the backend side to do it.
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        // `email` is UNIQUE, so re-submitting an existing address is really a
        // request to put that contact in another market — either alongside its
        // current ones (add-to-market) or instead of them (move-market).
        // Answered before validation so the response can name both the market
        // that already holds it and the one being asked for: a bare "email
        // already exists" left the marketing team with no next step but to
        // delete the contact and re-add it. Still a 422 with `errors.email`
        // populated, so any client reading only the standard validation shape
        // behaves exactly as before.
        if ($request->filled('email')) {
            $existing = MarketingContact::where('email', strtolower(trim((string) $request->input('email'))))->first();

            if ($existing) {
                return $this->existingContactResponse($existing, $this->requestedMarkets($request));
            }
        }

        $data = $request->validate([
            'email'      => ['required', 'email', 'max:255', 'unique:marketing_contacts,email'],
            'market'     => ['required_without:markets', 'string', 'max:50'],
            'markets'    => ['required_without:market', 'array', 'min:1', 'max:20'],
            'markets.*'  => ['string', 'max:50'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'company'    => ['nullable', 'string', 'max:150'],
            'country'    => ['nullable', 'string', 'max:100'],
            'vat_id'     => ['nullable', 'string', 'max:50'],
            'labels'     => ['nullable', 'string', 'max:255'],
            'status'     => ['nullable', 'in:subscribed,unsubscribed,unknown'],
        ]);

        $markets = $this->requestedMarkets($request);

        $contact = MarketingContact::create(array_merge(
            collect($data)->except(['markets'])->all(),
            [
                'email'             => strtolower($data['email']),
                'market'            => $markets[0],
                'status'            => $data['status'] ?? 'unknown',
                'source'            => 'manual',
                'unsubscribe_token' => $this->generateToken(),
                'imported_at'       => now(),
            ]
        ));

        $contact->addMarkets($markets);

        return response()->json([
            'data'    => $this->formatContact($contact->fresh()),
            'message' => 'Contact added.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/marketing-contacts/{id} — marketing.manage
    //
    // `market` (single) sets the primary market and guarantees membership of it;
    // `markets` (array) replaces the whole membership set. Use move/add/remove
    // for anything covering more than one contact.
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $contact = MarketingContact::findOrFail($id);

        $data = $request->validate([
            'email'      => ['sometimes', 'email', 'max:255', Rule::unique('marketing_contacts', 'email')->ignore($contact->id)],
            'market'     => ['sometimes', 'string', 'max:50'],
            'markets'    => ['sometimes', 'array', 'min:1', 'max:20'],
            'markets.*'  => ['string', 'max:50'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'company'    => ['sometimes', 'nullable', 'string', 'max:150'],
            'country'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'vat_id'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'labels'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'     => ['sometimes', 'in:subscribed,unsubscribed,unknown'],
        ]);

        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        if (isset($data['market'])) {
            $data['market'] = $this->normalizeMarket($data['market']);
        }

        $contact->update(collect($data)->except(['markets'])->all());

        if (isset($data['markets'])) {
            $contact->syncMarkets($this->normalizeMarkets($data['markets']));
        } elseif (isset($data['market'])) {
            // A single `market` is a move, matching the pre-multi-market
            // meaning of this field — the contact ends up in that market only.
            $contact->syncMarkets([$data['market']]);
        }

        return response()->json([
            'data'    => $this->formatContact($contact->fresh()),
            'message' => 'Contact updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/marketing-contacts/add-to-market — marketing.manage
    //
    // Puts contacts in an ADDITIONAL market, keeping every market they're
    // already in. This is the "my email is in TEST, I also want it in Germany"
    // case — the one thing the add form could never do, since `email` is
    // UNIQUE and a second row was impossible.
    //
    // Selectors (OR'd): contact_ids / emails / from_market. Same as
    // move-market, so one UI selection can drive either action.
    // -------------------------------------------------------------------------
    public function addToMarket(Request $request): JsonResponse
    {
        $data = $this->validateBulkMarketRequest($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $toMarket = $this->normalizeMarket($data['to_market']);
        $contacts = $this->resolveTargets($data);

        $added = 0;
        foreach ($contacts as $contact) {
            if ($contact->addMarkets([$toMarket]) !== []) {
                $added++;
            }
        }

        $this->logBulkAction('add', $request, $toMarket, null, $added, $contacts->count() - $added);

        return response()->json([
            'data' => [
                'to_market'        => $toMarket,
                'added'            => $added,
                'already_in_place' => $contacts->count() - $added,
                'not_found'        => $this->unmatchedEmails($data, $contacts),
                'contacts'         => $this->refreshedContacts($contacts),
            ],
            'message' => $added === 0
                ? 'No contacts needed adding — they are already in this market.'
                : $added . ' contact' . ($added === 1 ? '' : 's') . " added to \"{$toMarket}\".",
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/marketing-contacts/remove-from-market — marketing.manage
    //
    // Takes contacts OUT of one market without deleting them. Called with no
    // contact_ids/emails it clears the market entirely, which is how a market
    // is retired.
    //
    // A contact always keeps at least one market: removing its last one would
    // leave it invisible to every market-scoped list and campaign filter with
    // no way to find it again. Those contacts are reported in
    // `skipped_last_market` — use move-market (or DELETE the contact) instead.
    // -------------------------------------------------------------------------
    public function removeFromMarket(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market'        => ['required', 'string', 'max:50'],
            'contact_ids'   => ['nullable', 'array', 'max:5000'],
            'contact_ids.*' => ['integer'],
            'emails'        => ['nullable', 'array', 'max:5000'],
            'emails.*'      => ['string', 'max:255'],
        ]);

        $market = $this->normalizeMarket($data['market']);

        // Scope to actual members of the market, so an id/email selection can
        // never strip a market the contact isn't even in.
        $contacts = $this->resolveTargets([
            'contact_ids' => $data['contact_ids'] ?? null,
            'emails'      => $data['emails'] ?? null,
            'from_market' => $market,
            'intersect'   => ! empty($data['contact_ids']) || ! empty($data['emails']),
        ]);

        $removed          = 0;
        $skippedLastMarket = [];

        foreach ($contacts as $contact) {
            $result = $contact->removeMarkets([$market]);

            if ($result['refused_last']) {
                $skippedLastMarket[] = $contact->email;
            } elseif ($result['removed'] !== []) {
                $removed++;
            }
        }

        $this->logBulkAction('remove', $request, null, $market, $removed, count($skippedLastMarket));

        return response()->json([
            'data' => [
                'market'              => $market,
                'removed'             => $removed,
                'skipped_last_market' => $skippedLastMarket,
                'not_found'           => $this->unmatchedEmails($data, $contacts),
                'contacts'            => $this->refreshedContacts($contacts),
            ],
            'message' => $removed === 0
                ? 'No contacts were removed from this market.'
                : $removed . ' contact' . ($removed === 1 ? '' : 's') . " removed from \"{$market}\".",
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/marketing-contacts/move-market — marketing.manage
    //
    // Relocates contacts rather than accumulating markets — use add-to-market
    // when the contact should stay in its current market as well.
    //
    //   with `from_market`   — leaves that market for `to_market`, keeping any
    //                          OTHER markets the contact is in
    //   without `from_market` — `to_market` replaces the contact's markets
    //                           outright (the original single-market meaning)
    //
    // Selectors (OR'd): contact_ids / emails / from_market. Nothing is created
    // and nothing is deleted — an email in `emails` with no matching contact is
    // reported in `not_found` instead of being added, because "move" should
    // never quietly become "import".
    //
    // Subscription status is deliberately untouched here and in every other
    // market operation: moving an unsubscribed contact must never re-enter them
    // into a send, the same guarantee MarketingContactImportService makes.
    // -------------------------------------------------------------------------
    public function moveMarket(Request $request): JsonResponse
    {
        $data = $this->validateBulkMarketRequest($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $toMarket   = $this->normalizeMarket($data['to_market']);
        $fromMarket = ! empty($data['from_market']) ? $this->normalizeMarket($data['from_market']) : null;
        $contacts   = $this->resolveTargets($data);

        $moved = 0;
        foreach ($contacts as $contact) {
            $before = $contact->marketNames();

            if ($fromMarket !== null) {
                $contact->addMarkets([$toMarket]);
                $contact->removeMarkets([$fromMarket]);
            } else {
                $contact->syncMarkets([$toMarket]);
            }

            if ($contact->fresh()->marketNames() !== $before) {
                $moved++;
            }
        }

        $this->logBulkAction('move', $request, $toMarket, $fromMarket, $moved, $contacts->count() - $moved);

        return response()->json([
            'data' => [
                'to_market'        => $toMarket,
                'from_market'      => $fromMarket,
                'moved'            => $moved,
                'already_in_place' => $contacts->count() - $moved,
                'not_found'        => $this->unmatchedEmails($data, $contacts),
                'contacts'         => $this->refreshedContacts($contacts),
            ],
            'message' => $moved === 0
                ? 'No contacts needed moving.'
                : $moved . ' contact' . ($moved === 1 ? '' : 's') . " moved to \"{$toMarket}\".",
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/marketing-contacts/stats — marketing.manage
    // -------------------------------------------------------------------------
    public function stats(): JsonResponse
    {
        if (MarketingContact::supportsMultipleMarkets()) {
            $byMarket = MarketingContactMarket::query()
                ->selectRaw('market, COUNT(DISTINCT contact_id) as total')
                ->groupBy('market')
                ->orderBy('market')
                ->get();
        } else {
            $byMarket = MarketingContact::query()
                ->whereNotNull('market')
                ->selectRaw('market, COUNT(*) as total')
                ->groupBy('market')
                ->orderBy('market')
                ->get();
        }

        return response()->json([
            'data' => [
                'total'        => MarketingContact::count(),
                'subscribed'   => MarketingContact::where('status', 'subscribed')->count(),
                'unsubscribed' => MarketingContact::where('status', 'unsubscribed')->count(),
                'unknown'      => MarketingContact::where('status', 'unknown')->count(),
                // A contact in two markets counts under each, so these totals
                // can legitimately sum to more than `total`.
                'by_market'    => $byMarket->map(fn ($r) => ['market' => $r->market, 'total' => (int) $r->total])->values(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/marketing-contacts/{id} — marketing.manage
    // -------------------------------------------------------------------------
    public function destroy(int $id): JsonResponse
    {
        $contact = MarketingContact::findOrFail($id);
        $contact->delete();

        return response()->json(['message' => 'Contact removed.']);
    }

    // -------------------------------------------------------------------------

    /**
     * Shared validation for move-market / add-to-market. Returns the validated
     * array, or a 422 JsonResponse when no selector was given — that check
     * can't be expressed as a field rule because any ONE of three fields
     * satisfies it.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function validateBulkMarketRequest(Request $request): array|JsonResponse
    {
        $data = $request->validate([
            'to_market'     => ['required', 'string', 'max:50'],
            'contact_ids'   => ['nullable', 'array', 'max:5000'],
            'contact_ids.*' => ['integer'],
            'emails'        => ['nullable', 'array', 'max:5000'],
            'emails.*'      => ['string', 'max:255'],
            'from_market'   => ['nullable', 'string', 'max:50'],
        ]);

        if (empty($data['contact_ids']) && empty($data['emails']) && empty($data['from_market'])) {
            return response()->json([
                'message' => 'Specify which contacts to act on: contact_ids, emails, or from_market.',
                'errors'  => ['contact_ids' => ['Provide contact_ids, emails, or from_market.']],
            ], 422);
        }

        return $data;
    }

    /**
     * Resolves the contacts a bulk market operation applies to.
     *
     * Selectors are OR'd by default: a from_market sweep plus an explicit
     * id/email selection in one call acts on the union, not the intersection.
     * `intersect` flips that for remove-from-market, which must narrow an
     * id/email selection down to actual members of the market being cleared.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, MarketingContact>
     */
    private function resolveTargets(array $data): Collection
    {
        $ids    = $data['contact_ids'] ?? [];
        $emails = array_map(fn ($e) => strtolower(trim($e)), $data['emails'] ?? []);
        $from   = ! empty($data['from_market']) ? $this->normalizeMarket($data['from_market']) : null;

        $query = MarketingContact::query();

        if (MarketingContact::supportsMultipleMarkets()) {
            $query->with('marketMemberships');
        }

        $marketFilter = function ($q) use ($from) {
            if (MarketingContact::supportsMultipleMarkets()) {
                $q->whereHas('marketMemberships', fn ($m) => $m->where('market', $from));
            } else {
                $q->where('market', $from);
            }
        };

        if (! empty($data['intersect']) && $from !== null) {
            $query->where(function ($q) use ($ids, $emails) {
                if (! empty($ids)) {
                    $q->orWhereIn('id', $ids);
                }
                if (! empty($emails)) {
                    $q->orWhereIn('email', $emails);
                }
            });
            $marketFilter($query);

            return $query->get();
        }

        $query->where(function ($q) use ($ids, $emails, $from, $marketFilter) {
            if (! empty($ids)) {
                $q->orWhereIn('id', $ids);
            }
            if (! empty($emails)) {
                $q->orWhereIn('email', $emails);
            }
            if ($from !== null) {
                $q->orWhere(fn ($sub) => $marketFilter($sub));
            }
        });

        return $query->get();
    }

    /**
     * Emails the caller asked for that don't exist as contacts. Only meaningful
     * for the `emails` selector — ids or a market matching nothing is normal
     * (a stale list refresh, an already-empty market).
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, MarketingContact>  $contacts
     * @return array<int, string>
     */
    private function unmatchedEmails(array $data, Collection $contacts): array
    {
        if (empty($data['emails'])) {
            return [];
        }

        $requested = array_unique(array_map(fn ($e) => strtolower(trim($e)), $data['emails']));
        $matched   = $contacts->pluck('email')->map(fn ($e) => strtolower($e))->all();

        return array_values(array_diff($requested, $matched));
    }

    /**
     * @param  Collection<int, MarketingContact>  $contacts
     * @return array<int, array<string, mixed>>
     */
    private function refreshedContacts(Collection $contacts): array
    {
        if ($contacts->isEmpty()) {
            return [];
        }

        $query = MarketingContact::whereIn('id', $contacts->pluck('id'));

        if (MarketingContact::supportsMultipleMarkets()) {
            $query->with('marketMemberships');
        }

        return $query->get()->map(fn ($c) => $this->formatContact($c))->values()->all();
    }

    private function logBulkAction(
        string $action,
        Request $request,
        ?string $toMarket,
        ?string $fromMarket,
        int $affected,
        int $unaffected
    ): void {
        Log::info("[marketing_contacts] market {$action}", [
            'admin_id'    => $request->user()?->id,
            'to_market'   => $toMarket,
            'from_market' => $fromMarket,
            'affected'    => $affected,
            'unaffected'  => $unaffected,
        ]);
    }

    /**
     * The 422 an existing email gets from the add form, carrying everything the
     * UI needs to offer "move it" or "add it to this market too" in one click.
     *
     * @param  array<int, string>  $requestedMarkets
     */
    private function existingContactResponse(MarketingContact $existing, array $requestedMarkets): JsonResponse
    {
        $held   = $existing->marketNames();
        $target = $requestedMarkets[0] ?? null;
        $inList = fn (?string $m) => $m !== null && in_array($m, $held, true);

        $heldLabel = empty($held) ? 'no market' : '"' . implode('", "', $held) . '"';

        return response()->json([
            'message' => $target !== null && ! $inList($target)
                ? "This contact is already on the marketing list, in {$heldLabel}. Add it to \"{$target}\" as well, or move it there."
                : "This contact is already on the marketing list, in {$heldLabel}.",
            'errors'  => ['email' => ['This email is already on the marketing list.']],
            'code'    => 'contact_exists',
            'data'    => [
                'existing_contact' => $this->formatContact($existing),
                'existing_markets' => $held,
                'target_market'    => $target,
                // Both are offerable whenever the contact isn't already there:
                // add keeps its current markets, move replaces them.
                'can_add_market'   => $target !== null && ! $inList($target),
                'can_move'         => $target !== null && ! $inList($target),
            ],
        ], 422);
    }

    /**
     * Markets requested on a create/duplicate check, from either `markets[]` or
     * the single `market`, normalized and de-duplicated.
     *
     * @return array<int, string>
     */
    private function requestedMarkets(Request $request): array
    {
        $raw = $request->input('markets');

        if (is_array($raw) && $raw !== []) {
            return $this->normalizeMarkets($raw);
        }

        if ($request->filled('market')) {
            return $this->normalizeMarkets([$request->input('market')]);
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $markets
     * @return array<int, string>
     */
    private function normalizeMarkets(array $markets): array
    {
        $normalized = array_map(fn ($m) => $this->normalizeMarket((string) $m), $markets);

        return array_values(array_unique(array_filter($normalized)));
    }

    /**
     * Lowercased, trimmed, dashed — so "Croatia", "croatia ", "CROATIA"
     * and "croatia-market" all collapse to the same market key instead of
     * silently fragmenting a campaign's audience across near-duplicate tags.
     */
    private function normalizeMarket(string $market): string
    {
        return Str::slug(trim($market));
    }

    private function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (MarketingContact::where('unsubscribe_token', $token)->exists());

        return $token;
    }

    private function formatContact(MarketingContact $c): array
    {
        return [
            'id'         => $c->id,
            'email'      => $c->email,
            'first_name' => $c->first_name,
            'last_name'  => $c->last_name,
            'phone'      => $c->phone,
            'company'    => $c->company,
            'country'    => $c->country,
            // `market` = primary market (unchanged contract). `markets` = every
            // market the contact belongs to; prefer it in new UI.
            'market'     => $c->market,
            'markets'    => $c->marketNames(),
            'vat_id'     => $c->vat_id,
            'labels'     => $c->labels,
            'source'     => $c->source,
            'status'     => $c->status,
            'imported_at' => $c->imported_at?->toIso8601String(),
            'created_at'  => $c->created_at?->toIso8601String(),
        ];
    }
}
