<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingContact;
use App\Services\MarketingContactImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminMarketingContactController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/marketing-contacts/markets — marketing.manage
    //
    // Auto-discovered from actual data, not a hardcoded list — a new market
    // shows up here the moment the first contact is imported/added under it,
    // with no backend change needed. Powers the market picker/tag UI.
    // -------------------------------------------------------------------------
    public function markets(): JsonResponse
    {
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

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('market')) {
            $query->where('market', $this->normalizeMarket($request->market));
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
    // Manual single-contact add, scoped to a market — the marketing team
    // can add one lead they picked up by hand without needing a whole CSV
    // import for it, and without needing anyone on the backend side to do it.
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'      => ['required', 'email', 'max:255', 'unique:marketing_contacts,email'],
            'market'     => ['required', 'string', 'max:50'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'company'    => ['nullable', 'string', 'max:150'],
            'country'    => ['nullable', 'string', 'max:100'],
            'vat_id'     => ['nullable', 'string', 'max:50'],
            'labels'     => ['nullable', 'string', 'max:255'],
            'status'     => ['nullable', 'in:subscribed,unsubscribed,unknown'],
        ]);

        $contact = MarketingContact::create(array_merge($data, [
            'email'             => strtolower($data['email']),
            'market'            => $this->normalizeMarket($data['market']),
            'status'            => $data['status'] ?? 'unknown',
            'source'            => 'manual',
            'unsubscribe_token' => $this->generateToken(),
            'imported_at'       => now(),
        ]));

        return response()->json([
            'data'    => $this->formatContact($contact),
            'message' => 'Contact added.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/marketing-contacts/{id} — marketing.manage
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $contact = MarketingContact::findOrFail($id);

        $data = $request->validate([
            'email'      => ['sometimes', 'email', 'max:255', Rule::unique('marketing_contacts', 'email')->ignore($contact->id)],
            'market'     => ['sometimes', 'string', 'max:50'],
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

        $contact->update($data);

        return response()->json(['data' => $this->formatContact($contact->fresh()), 'message' => 'Contact updated.']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/marketing-contacts/stats — marketing.manage
    // -------------------------------------------------------------------------
    public function stats(): JsonResponse
    {
        $byMarket = MarketingContact::query()
            ->whereNotNull('market')
            ->selectRaw('market, COUNT(*) as total')
            ->groupBy('market')
            ->orderBy('market')
            ->get()
            ->map(fn ($r) => ['market' => $r->market, 'total' => (int) $r->total])
            ->values();

        return response()->json([
            'data' => [
                'total'        => MarketingContact::count(),
                'subscribed'   => MarketingContact::where('status', 'subscribed')->count(),
                'unsubscribed' => MarketingContact::where('status', 'unsubscribed')->count(),
                'unknown'      => MarketingContact::where('status', 'unknown')->count(),
                'by_market'    => $byMarket,
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
            'market'     => $c->market,
            'vat_id'     => $c->vat_id,
            'labels'     => $c->labels,
            'source'     => $c->source,
            'status'     => $c->status,
            'imported_at' => $c->imported_at?->toIso8601String(),
            'created_at'  => $c->created_at?->toIso8601String(),
        ];
    }
}
