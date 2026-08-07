<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePartnerOrganisationRequest;
use App\Http\Requests\Admin\StorePartnerUserRequest;
use App\Models\PartnerOrganisation;
use App\Models\PartnerUser;
use App\Rules\StrongPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Admin management of partner organisations and their users.
 *
 * Partners are created here — there is no self-signup, by design.
 */
class AdminPartnerController extends Controller
{
    /**
     * GET /api/v1/admin/partners
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'market'   => ['nullable', 'string', 'max:100'],
            'status'   => ['nullable', Rule::in(['active', 'suspended'])],
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = PartnerOrganisation::withCount(['users', 'sales'])->orderBy('name');

        if ($request->filled('market')) {
            // Market is derived from country, so filtering on it is a
            // case-insensitive country match rather than a column lookup.
            $query->whereRaw('LOWER(country) = ?', [mb_strtolower(trim($request->input('market')))]);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('country', 'like', $term));
        }

        $paginated = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $paginated->getCollection()->map(fn ($p) => $this->formatOrganisation($p))->values(),
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
     * GET /api/v1/admin/partners/{id}
     */
    public function show(int $id): JsonResponse
    {
        $partner = PartnerOrganisation::withCount('sales')->with('users')->find($id);

        if (! $partner) {
            return response()->json(['message' => 'Partner not found.'], 404);
        }

        return response()->json([
            'data' => $this->formatOrganisation($partner) + [
                'users' => $partner->users->map(fn ($u) => $this->formatUser($u))->values(),
                'notes' => $partner->notes,
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/partners
     */
    public function store(StorePartnerOrganisationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Checked before the transaction so a duplicate phone fails as a clean
        // 422 rather than a database integrity error.
        if (isset($data['owner'])) {
            $phone = PartnerUser::normalisePhone($data['owner']['phone']);

            if (PartnerUser::where('phone', $phone)->exists()) {
                return response()->json([
                    'message' => 'That phone number already belongs to a partner user.',
                    'errors'  => ['owner.phone' => ['That phone number already belongs to a partner user.']],
                ], 422);
            }
        }

        $partner = DB::transaction(function () use ($data) {
            $partner = PartnerOrganisation::create([
                'name'             => $data['name'],
                'country'          => $data['country'],
                'country_code'     => $data['country_code'] ?? null,
                'default_currency' => $data['default_currency'],
                'contact_email'    => $data['contact_email'] ?? null,
                'contact_phone'    => $data['contact_phone'] ?? null,
                'status'           => $data['status'] ?? 'active',
                'notes'            => $data['notes'] ?? null,
            ]);

            if (isset($data['owner'])) {
                PartnerUser::create([
                    'partner_org_id'  => $partner->id,
                    'name'            => $data['owner']['name'],
                    'phone'           => PartnerUser::normalisePhone($data['owner']['phone']),
                    'pin_hash'        => Hash::make($data['owner']['pin']),
                    'role'            => 'owner',
                    'is_active'       => true,
                    'must_change_pin' => true,
                ]);
            }

            return $partner;
        });

        return response()->json([
            'data'    => $this->formatOrganisation($partner->loadCount(['users', 'sales'])),
            'message' => 'Partner created.',
        ], 201);
    }

    /**
     * PATCH /api/v1/admin/partners/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $partner = PartnerOrganisation::find($id);

        if (! $partner) {
            return response()->json(['message' => 'Partner not found.'], 404);
        }

        $data = $request->validate([
            'name'             => ['sometimes', 'required', 'string', 'max:150'],
            'country'          => ['sometimes', 'required', 'string', 'max:100'],
            'country_code'     => ['sometimes', 'nullable', 'string', 'size:2'],
            'default_currency' => ['sometimes', 'required', 'string', 'size:3', Rule::in(config('partner.currencies', []))],
            'contact_email'    => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone'    => ['sometimes', 'nullable', 'string', 'max:30'],
            'status'           => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
            'notes'            => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $partner->fill($data)->save();

        return response()->json([
            'data'    => $this->formatOrganisation($partner->loadCount(['users', 'sales'])),
            'message' => 'Partner updated.',
        ]);
    }

    /**
     * POST /api/v1/admin/partners/{id}/users
     */
    public function storeUser(StorePartnerUserRequest $request, int $id): JsonResponse
    {
        $partner = PartnerOrganisation::find($id);

        if (! $partner) {
            return response()->json(['message' => 'Partner not found.'], 404);
        }

        $phone = PartnerUser::normalisePhone($request->input('phone'));

        if (PartnerUser::where('phone', $phone)->exists()) {
            return response()->json([
                'message' => 'That phone number already belongs to a partner user.',
                'errors'  => ['phone' => ['That phone number already belongs to a partner user.']],
            ], 422);
        }

        $user = PartnerUser::create([
            'partner_org_id'  => $partner->id,
            'name'            => $request->input('name'),
            'phone'           => $phone,
            'pin_hash'        => Hash::make($request->input('pin')),
            'role'            => $request->input('role', 'staff'),
            'is_active'       => true,
            'must_change_pin' => true,
        ]);

        return response()->json([
            'data'    => $this->formatUser($user),
            'message' => 'Partner user created.',
        ], 201);
    }

    /**
     * PATCH /api/v1/admin/partner-users/{id}
     *
     * Deactivation, PIN reset and unlock. There is no "show me their PIN" —
     * only a hash is stored, and resetting forces a change on next sign-in.
     */
    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = PartnerUser::find($id);

        if (! $user) {
            return response()->json(['message' => 'Partner user not found.'], 404);
        }

        $data = $request->validate([
            'name'      => ['sometimes', 'required', 'string', 'max:150'],
            'role'      => ['sometimes', 'required', Rule::in(['owner', 'staff'])],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'pin'       => ['sometimes', 'required', 'string', new StrongPin()],
            'unlock'    => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('pin', $data)) {
            $user->pin_hash        = Hash::make($data['pin']);
            $user->must_change_pin = true;
            $user->pin_changed_at  = now();

            // An admin-issued PIN is known to someone else, so every existing
            // session is dropped — otherwise a reset prompted by a suspected
            // compromise would leave the compromised device signed in.
            $user->tokens()->delete();

            unset($data['pin']);
        }

        if (! empty($data['unlock'])) {
            $user->failed_pin_attempts = 0;
            $user->locked_until        = null;
        }
        unset($data['unlock']);

        // Deactivating must also end the current session, for the same reason.
        if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
            $user->tokens()->delete();
        }

        $user->fill($data)->save();

        return response()->json([
            'data'    => $this->formatUser($user),
            'message' => 'Partner user updated.',
        ]);
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function formatOrganisation(PartnerOrganisation $partner): array
    {
        return [
            'id'               => $partner->id,
            'name'             => $partner->name,
            'country'          => $partner->country,
            'country_code'     => $partner->country_code,
            'market'           => $partner->market,
            'default_currency' => $partner->default_currency,
            'contact_email'    => $partner->contact_email,
            'contact_phone'    => $partner->contact_phone,
            'status'           => $partner->status,
            'users_count'      => $partner->users_count ?? null,
            'sales_count'      => $partner->sales_count ?? null,
            'created_at'       => $partner->created_at?->toIso8601String(),
        ];
    }

    private function formatUser(PartnerUser $user): array
    {
        return [
            'id'              => $user->id,
            'partner_org_id'  => $user->partner_org_id,
            'name'            => $user->name,
            'phone'           => $user->phone,
            'role'            => $user->role,
            'is_active'       => (bool) $user->is_active,
            'must_change_pin' => (bool) $user->must_change_pin,
            'is_locked'       => $user->isLocked(),
            'locked_until'    => $user->locked_until?->toIso8601String(),
            'last_login_at'   => $user->last_login_at?->toIso8601String(),
            'created_at'      => $user->created_at?->toIso8601String(),
        ];
    }
}
