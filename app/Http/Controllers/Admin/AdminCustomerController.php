<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CustomerInvitation;
use App\Mail\CustomerPasswordReset;
use App\Models\BlockedEntity;
use App\Models\Customer;
use App\Services\CustomerApprovalService;
use App\Services\CustomerTimelineService;
use App\Services\SecurityEventService;
use App\Support\CustomerLifecyclePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AdminCustomerController extends Controller
{
    // ── GET /admin/customers ──────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'            => ['nullable', 'in:active,suspended,banned,locked'],
            'onboarding_status' => ['nullable', 'in:pending_review,approved,invited,active,rejected,blocked'],
            'type'              => ['nullable', 'in:b2b,b2c,wix'],
            'since'             => ['nullable', 'date'],
            'search'            => ['nullable', 'string', 'max:100'],
            'per_page'          => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Customer::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('onboarding_status')) {
            $query->where('onboarding_status', $request->onboarding_status);
        }

        if ($request->filled('type')) {
            if ($request->type === 'wix') {
                $query->where('imported_from_wix', true);
            } else {
                $query->where('customer_type', $request->type);
            }
        }

        if ($request->filled('since')) {
            $query->where('created_at', '>=', $request->since);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                  ->orWhere('last_name', 'like', $term)
                  ->orWhere('email', 'like', $term)
                  ->orWhere('company_name', 'like', $term);
            });
        }

        $paginated = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $paginated->map(fn ($c) => $this->formatSummary($c))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/customers/{id} ─────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $customer = Customer::with([
            'loginHistory' => fn ($q) => $q->limit(50),
            'quoteRequests' => fn ($q) => $q->orderByDesc('created_at')->limit(20),
        ])->findOrFail($id);

        $orders = DB::table('orders')
            ->where('customer_email', $customer->email)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'ref', 'total', 'status', 'payment_status', 'created_at']);

        $sessions = $customer->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'ip_address'  => null,
                'user_agent'  => $t->name,
                'created_at'  => $t->created_at?->toIso8601String(),
                'last_active' => $t->last_used_at?->toIso8601String(),
            ]);

        $data = $this->formatSummary($customer);
        $data['phone']               = $customer->phone;
        $data['country']             = $customer->country;
        $data['admin_notes']         = $customer->admin_notes;
        $data['login_history']       = $customer->loginHistory->map(fn ($h) => [
            'id'         => $h->id,
            'success'    => $h->success,
            'ip_address' => $h->ip_address,
            'user_agent' => $h->user_agent,
            'location'   => $h->location,
            'created_at' => $h->created_at?->toIso8601String(),
        ])->values();
        $data['active_sessions']     = $sessions->values();
        $data['orders']              = collect($orders)->map(fn ($o) => [
            'id'             => $o->id,
            'order_ref'      => $o->ref,
            'total'          => (float) $o->total,
            'status'         => $o->status,
            'payment_status' => $o->payment_status,
            'created_at'     => $o->created_at,
        ])->values();
        $data['quote_requests']      = $customer->quoteRequests->map(fn ($q) => [
            'id'            => $q->id,
            'ref_number'    => $q->ref_number,
            'tyre_category' => $q->tyre_category ?? null,
            'status'        => $q->status,
            'created_at'    => $q->created_at?->toIso8601String(),
        ])->values();

        return response()->json(['data' => $data]);
    }

    // ── PATCH /admin/customers/{id} ───────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'admin_notes'   => ['nullable', 'string'],
            'customer_type' => ['nullable', 'in:b2b,b2c'],
            'company_name'  => ['nullable', 'string', 'max:200'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'country'       => ['nullable', 'string', 'max:100'],
        ]);

        $customer->update($data);

        SecurityEventService::log(
            'account_changes', $customer->id,
            $request->ip(), $request->userAgent(),
            'Admin updated customer profile', 'info'
        );

        return response()->json(['success' => true, 'data' => $this->formatSummary($customer->fresh()), 'message' => 'Customer updated successfully.']);
    }

    // ── DELETE /admin/customers/{id} ──────────────────────────────────────────

    public function destroy(int $id): \Illuminate\Http\Response
    {
        $customer = Customer::findOrFail($id);
        $customer->tokens()->delete();
        $customer->forceDelete();

        return response()->noContent();
    }

    // ── POST /admin/customers/{id}/suspend ────────────────────────────────────

    public function suspend(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $customer->update(['status' => 'suspended', 'is_active' => false]);
        $customer->tokens()->delete();

        SecurityEventService::log(
            'account_suspend', $customer->id,
            $request->ip(), $request->userAgent(),
            'Account suspended by admin', 'warning'
        );

        return response()->json(['success' => true, 'message' => 'Account suspended successfully.']);
    }

    // ── POST /admin/customers/{id}/ban ────────────────────────────────────────

    public function ban(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $customer->update(['status' => 'banned', 'is_active' => false]);
        $customer->tokens()->delete();

        // Flag email and last known IP in blocklist
        BlockedEntity::upsert(
            [['type' => 'email', 'value' => $customer->email, 'reason' => 'Account banned by admin']],
            ['type', 'value'], ['reason']
        );

        if ($customer->last_login_ip) {
            BlockedEntity::upsert(
                [['type' => 'ip', 'value' => $customer->last_login_ip, 'reason' => 'IP associated with banned account']],
                ['type', 'value'], ['reason']
            );
        }

        SecurityEventService::log(
            'account_ban', $customer->id,
            $request->ip(), $request->userAgent(),
            'Account banned by admin', 'critical'
        );

        return response()->json(['success' => true, 'message' => 'Account banned successfully.']);
    }

    // ── POST /admin/customers/{id}/activate ───────────────────────────────────

    public function activate(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $customer->update([
            'status'             => 'active',
            'is_active'          => true,
            'failed_login_count' => 0,
        ]);

        SecurityEventService::log(
            'account_changes', $customer->id,
            $request->ip(), $request->userAgent(),
            'Account activated by admin', 'info'
        );

        return response()->json(['success' => true, 'message' => 'Account activated successfully.']);
    }

    // ── POST /admin/customers/{id}/unlock ─────────────────────────────────────

    public function unlock(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        if ($customer->status === 'locked') {
            $customer->update([
                'status'             => 'active',
                'is_active'          => true,
                'failed_login_count' => 0,
            ]);
        }

        SecurityEventService::log(
            'account_unlock', $customer->id,
            $request->ip(), $request->userAgent(),
            'Account unlocked by admin', 'info'
        );

        return response()->json(['success' => true, 'message' => 'Account unlocked successfully.']);
    }

    // ── POST /admin/customers/{id}/logout-all ─────────────────────────────────

    public function logoutAll(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $count    = $customer->tokens()->count();
        $customer->tokens()->delete();

        SecurityEventService::log(
            'account_changes', $customer->id,
            $request->ip(), $request->userAgent(),
            "All sessions invalidated by admin ({$count} tokens revoked)", 'info'
        );

        return response()->json(['success' => true, 'message' => "{$count} session(s) invalidated successfully."]);
    }

    // ── POST /admin/customers/{id}/force-password-reset ───────────────────────

    public function forcePasswordReset(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $token = Str::random(64);

        DB::table('password_reset_tokens')->upsert(
            [
                'email'      => $customer->email,
                'token'      => Hash::make($token),
                'created_at' => now(),
            ],
            ['email'],
            ['token', 'created_at']
        );

        $customer->tokens()->delete();

        $frontendUrl = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');
        $resetUrl    = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($customer->email);

        Mail::to($customer->email)->send(new CustomerPasswordReset($customer, $resetUrl));

        SecurityEventService::log(
            'password_reset', $customer->id,
            $request->ip(), $request->userAgent(),
            'Force password reset initiated by admin', 'info'
        );

        return response()->json(['success' => true, 'message' => 'Password reset email sent and sessions invalidated.']);
    }

    // ── POST /admin/customers ─────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_type' => ['required', 'in:b2c,b2b'],
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['required', 'string', 'max:100'],
            'email'         => ['required', 'email', 'max:255', 'unique:customers,email'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'country'       => ['nullable', 'string', 'max:100'],
            'company_name'  => ['nullable', 'string', 'max:200'],
            'vat_number'    => ['nullable', 'string', 'max:20'],
            'industry'      => ['nullable', 'string', 'max:100'],
            'admin_notes'   => ['nullable', 'string'],
        ]);

        $customer = Customer::create([
            ...$data,
            'password'          => Hash::make(Str::random(32)),
            'onboarding_status' => 'invited',
            'is_active'         => false,
            'must_reset_password' => true,
        ]);

        SecurityEventService::log(
            'customer_invited', $customer->id,
            $request->ip(), $request->userAgent(),
            'Customer account created and invited by admin', 'info'
        );

        $this->sendInvitationEmail($customer);

        return response()->json([
            'success' => true,
            'data'    => $this->formatSummary($customer->fresh()),
            'message' => 'Customer account created and invitation email sent.',
        ], 201);
    }

    // ── POST /admin/customers/{id}/approve ────────────────────────────────────

    public function approve(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        // CRM-8 buyer approval — triggered when a `profile` is supplied.
        // Applies an access profile, stamps approval audit fields, sets tier,
        // and records a timeline event. Backward compatible: requests without a
        // `profile` fall through to the original CRM-1 onboarding behaviour.
        if ($request->filled('profile')) {
            $data = $request->validate([
                'profile'    => ['required', \Illuminate\Validation\Rule::in(CustomerApprovalService::PROFILES)],
                'buyer_tier' => ['nullable', \Illuminate\Validation\Rule::in(CustomerApprovalService::TIERS)],
                'notes'      => ['nullable', 'string', 'max:2000'],
            ]);

            $customer = app(CustomerApprovalService::class)->approveBuyer(
                $customer,
                $data['profile'],
                $data['buyer_tier'] ?? null,
                $request->user(),
                $data['notes'] ?? null
            );

            SecurityEventService::log(
                'customer_approved', $customer->id,
                $request->ip(), $request->userAgent(),
                "Buyer approved by admin (profile: {$data['profile']})", 'info'
            );

            return response()->json([
                'success' => true,
                'data'    => $this->formatSummary($customer->fresh()->load('approvedBy')),
                'message' => "Buyer approved as {$data['profile']}.",
            ]);
        }

        // ── Legacy CRM-1 onboarding approval (unchanged) ──────────────────────
        if (! in_array($customer->onboarding_status ?? 'active', ['pending_review', 'rejected'], true)) {
            return response()->json(['message' => 'Customer is not in a reviewable state.'], 422);
        }

        $customer->update(['onboarding_status' => 'approved']);

        SecurityEventService::log(
            'customer_approved', $customer->id,
            $request->ip(), $request->userAgent(),
            'Customer approved by admin', 'info'
        );

        return response()->json([
            'success' => true,
            'data'    => $this->formatSummary($customer->fresh()),
            'message' => 'Customer approved. Use the invite action to send the activation email.',
        ]);
    }

    // ── POST /admin/customers/{id}/reject ─────────────────────────────────────

    public function reject(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $customer->update([
            'onboarding_status' => 'rejected',
            'is_active'         => false,
            // CRM-8 — persist the structured rejection reason alongside admin_notes.
            'rejection_reason'  => $data['reason'] ?? $customer->rejection_reason,
            'admin_notes'       => $customer->admin_notes
                ? $customer->admin_notes . "\n[Rejected] " . ($data['reason'] ?? '')
                : '[Rejected] ' . ($data['reason'] ?? ''),
        ]);

        $customer->tokens()->delete();

        // CRM-8 timeline
        CustomerTimelineService::record(
            $customer->id,
            'customer_rejected',
            'Customer rejected',
            $data['reason'] ?? 'Customer rejected by admin.',
            ['reason' => $data['reason'] ?? null],
            $request->user()?->id
        );

        SecurityEventService::log(
            'customer_rejected', $customer->id,
            $request->ip(), $request->userAgent(),
            'Customer rejected by admin' . ($data['reason'] ? ': ' . $data['reason'] : ''), 'warning'
        );

        return response()->json([
            'success' => true,
            'data'    => $this->formatSummary($customer->fresh()),
            'message' => 'Customer rejected.',
        ]);
    }

    // ── POST /admin/customers/{id}/invite ─────────────────────────────────────

    public function invite(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $allowedStates = ['pending_review', 'approved', 'rejected'];
        if (! in_array($customer->onboarding_status ?? 'active', $allowedStates, true)) {
            return response()->json(['message' => 'Customer cannot be invited in their current state.'], 422);
        }

        $customer->update([
            'onboarding_status' => 'invited',
            'is_active'         => false,
            'must_reset_password' => true,
        ]);

        $this->sendInvitationEmail($customer);

        SecurityEventService::log(
            'customer_invited', $customer->id,
            $request->ip(), $request->userAgent(),
            'Customer invitation sent by admin', 'info'
        );

        return response()->json([
            'success' => true,
            'data'    => $this->formatSummary($customer->fresh()),
            'message' => 'Invitation email sent.',
        ]);
    }

    // ── POST /admin/customers/{id}/resend-invite ──────────────────────────────

    public function resendInvite(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        if (! in_array($customer->onboarding_status ?? 'active', ['invited', 'approved'], true)) {
            return response()->json(['message' => 'Customer does not have a pending invitation.'], 422);
        }

        $this->sendInvitationEmail($customer);

        SecurityEventService::log(
            'customer_invited', $customer->id,
            $request->ip(), $request->userAgent(),
            'Customer invitation resent by admin', 'info'
        );

        return response()->json([
            'success' => true,
            'message' => 'Invitation email resent.',
        ]);
    }

    // ── POST /admin/customers/{id}/block ──────────────────────────────────────

    public function blockOnboarding(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $customer->update([
            'onboarding_status' => 'blocked',
            'is_active'         => false,
            'admin_notes'       => $customer->admin_notes
                ? $customer->admin_notes . "\n[Blocked] " . ($data['reason'] ?? '')
                : '[Blocked] ' . ($data['reason'] ?? ''),
        ]);

        $customer->tokens()->delete();

        SecurityEventService::log(
            'customer_blocked', $customer->id,
            $request->ip(), $request->userAgent(),
            'Customer blocked by admin' . ($data['reason'] ? ': ' . $data['reason'] : ''), 'warning'
        );

        return response()->json([
            'success' => true,
            'data'    => $this->formatSummary($customer->fresh()),
            'message' => 'Customer blocked.',
        ]);
    }

    // ── GET /admin/customers/{id}/sessions ───────────────────────────────────

    public function sessions(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $sessions = $customer->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'ip_address'  => null,
                'user_agent'  => $t->name,
                'created_at'  => $t->created_at?->toIso8601String(),
                'last_active' => $t->last_used_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $sessions->values()]);
    }

    // ── GET /admin/customers/export ───────────────────────────────────────────

    public function export(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['nullable', 'in:active,suspended,banned,locked'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Customer::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $paginated = $query->paginate($request->integer('per_page', 200));

        return response()->json([
            'data' => $paginated->map(fn ($c) => $this->formatSummary($c))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    // ── PATCH /admin/customers/{id}/access ───────────────────────────────────

    public function updateAccess(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'customer_segment'               => ['nullable', 'in:private_buyer,dealer,workshop,fleet,exporter,distributor,partner,unknown'],
            'access_level'                   => ['nullable', 'in:inquiry_only,quote_only,approved_buyer,wholesale_buyer,restricted,blocked'],
            'market_region'                  => ['nullable', 'in:eu,africa,middle_east,global,unknown'],
            'approved_for_checkout'          => ['nullable', 'boolean'],
            'approved_for_quotes'            => ['nullable', 'boolean'],
            'approved_for_wholesale_pricing' => ['nullable', 'boolean'],
            'approved_for_documents'         => ['nullable', 'boolean'],
        ]);

        // Only update fields that were explicitly sent
        $update = array_filter($data, fn ($v) => $v !== null);

        if (empty($update)) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        // If blocking, also revoke tokens
        if (isset($update['access_level']) && $update['access_level'] === 'blocked') {
            $customer->tokens()->delete();
        }

        $customer->update($update);

        SecurityEventService::log(
            'account_changes', $customer->id,
            $request->ip(), $request->userAgent(),
            'Access control updated by admin: ' . implode(', ', array_keys($update)), 'info'
        );

        return response()->json([
            'success' => true,
            'data'    => $this->formatSummary($customer->fresh()),
            'message' => 'Access control updated.',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatSummary(Customer $c): array
    {
        return [
            'id'                             => $c->id,
            'first_name'                     => $c->first_name,
            'last_name'                      => $c->last_name,
            'email'                          => $c->email,
            'phone'                          => $c->phone,
            'country'                        => $c->country,
            'company_name'                   => $c->company_name,
            'vat_number'                     => $c->vat_number,
            'customer_type'                  => $c->customer_type,
            'status'                         => $c->status ?? 'active',
            'onboarding_status'              => $c->onboarding_status ?? 'active',
            'last_login_at'                  => $c->last_login_at?->toIso8601String(),
            'last_login_ip'                  => $c->last_login_ip,
            'last_login_location'            => $c->last_login_location,
            'failed_login_count'             => (int) ($c->failed_login_count ?? 0),
            'is_locked'                      => $c->status === 'locked',
            'is_active'                      => (bool) $c->is_active,
            'email_verified'                 => (bool) $c->email_verified_at,
            'imported_from_wix'              => (bool) $c->imported_from_wix,
            'created_at'                     => $c->created_at?->toIso8601String(),
            // Segmentation & access (CRM-4)
            'customer_segment'               => $c->customer_segment ?? 'unknown',
            'access_level'                   => $c->access_level ?? 'inquiry_only',
            'market_region'                  => $c->market_region ?? 'unknown',
            'approved_for_checkout'          => (bool) ($c->approved_for_checkout ?? false),
            'approved_for_quotes'            => (bool) ($c->approved_for_quotes ?? true),
            'approved_for_wholesale_pricing' => (bool) ($c->approved_for_wholesale_pricing ?? false),
            'approved_for_documents'         => (bool) ($c->approved_for_documents ?? false),
        ] + CustomerLifecyclePresenter::fields($c); // Buyer lifecycle (CRM-8)
    }

    private function sendInvitationEmail(Customer $customer): void
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->upsert(
            [
                'email'      => $customer->email,
                'token'      => Hash::make($token),
                'created_at' => now(),
            ],
            ['email'],
            ['token', 'created_at']
        );

        // Invitation link expires in 48 hours (vs 60 min for standard password reset)
        $frontendUrl   = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');
        $activationUrl = $frontendUrl . '/activate?token=' . $token . '&email=' . urlencode($customer->email);

        Mail::to($customer->email)->send(new CustomerInvitation($customer, $activationUrl));
    }
}
