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
            'customer_type'     => ['required', 'in:b2c,b2b'],
            'first_name'        => ['required', 'string', 'max:100'],
            'last_name'         => ['nullable', 'string', 'max:100'],
            'email'             => ['required', 'email', 'max:255', 'unique:customers,email'],
            'phone'             => ['nullable', 'string', 'max:50'],
            'country'           => ['nullable', 'string', 'max:100'],
            // Company is mandatory for B2B, optional (ignored) for B2C.
            'company_name'      => ['nullable', 'required_if:customer_type,b2b', 'string', 'max:200'],
            'vat_number'        => ['nullable', 'string', 'max:20'],
            'industry'          => ['nullable', 'string', 'max:100'],
            // CRM-4 portal access — maps to an approval profile (defaults to
            // approved_buyer = quotes + checkout + documents).
            'access_level'      => ['nullable', \Illuminate\Validation\Rule::in(CustomerApprovalService::PROFILES)],
            'send_invitation'   => ['required', 'boolean'],
            // Accepted from the admin modal but informational only — the backend
            // derives the real onboarding state itself (created_via is logged).
            'onboarding_status' => ['nullable', 'string', 'max:30'],
            'created_via'       => ['nullable', 'string', 'max:50'],
            // The modal sends `notes` (internal); `admin_notes` accepted as alias.
            'notes'             => ['nullable', 'string'],
            'admin_notes'       => ['nullable', 'string'],
        ], [
            'company_name.required_if' => 'A company name is required for B2B customers.',
        ]);

        $profile        = $data['access_level'] ?? 'approved_buyer';
        $sendInvitation = (bool) $data['send_invitation'];
        $notes          = $data['notes'] ?? $data['admin_notes'] ?? null;

        $service = app(CustomerApprovalService::class);

        $customer = DB::transaction(function () use ($data, $profile, $notes, $service, $request) {
            // No usable password — the customer sets their own via the invitation
            // link. must_reset_password keeps them in the invite flow, so the
            // approval profile grants access flags without prematurely flipping
            // them to a "can log in now" state (CustomerApprovalService respects
            // this and leaves onboarding_status = 'approved').
            $customer = Customer::create([
                'customer_type'       => $data['customer_type'],
                'first_name'          => $data['first_name'],
                'last_name'           => $data['last_name'] ?? '',
                'email'               => $data['email'],
                'phone'               => $data['phone'] ?? null,
                'country'             => $data['country'] ?? null,
                'company_name'        => $data['company_name'] ?? null,
                'vat_number'          => $data['vat_number'] ?? null,
                'industry'            => $data['industry'] ?? null,
                'admin_notes'         => $notes,
                'password'            => Hash::make(Str::random(40)),
                'must_reset_password' => true,
                'is_active'           => false,
                'onboarding_status'   => 'approved',
            ]);

            // Apply CRM-4 access flags + CRM-8 approval audit in one auditable
            // step (stamps approved_by/approved_at, records a timeline event).
            return $service->approveBuyer($customer, $profile, null, $request->user(), $notes);
        });

        SecurityEventService::log(
            'customer_created', $customer->id,
            $request->ip(), $request->userAgent(),
            "Customer account created by admin (access: {$profile}, via: " . ($data['created_via'] ?? 'admin') . ')', 'info'
        );

        // Invitation email — sent synchronously (CustomerInvitation is not
        // queued) so a misconfigured mailer surfaces in the response rather than
        // failing silently. This link is the customer's only way to set a
        // password and reach the portal.
        $invite = ['attempted' => false, 'sent' => false, 'error' => null];
        if ($sendInvitation) {
            $invite = $this->sendInvitationEmail($customer);
            if ($invite['sent']) {
                $customer->update(['onboarding_status' => 'invited', 'must_reset_password' => true]);
            }
        }

        $customer = $customer->fresh()->load('approvedBy');

        $message = 'Customer created.';
        if ($sendInvitation) {
            $message .= $invite['sent']
                ? ' Invitation email sent.'
                : ' Invitation email failed to send — check mail config and use “Resend invite”.';
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge($this->formatSummary($customer), ['invitation_email' => $invite]),
            'message' => $message,
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

            $service  = app(CustomerApprovalService::class);
            $customer = $service->approveBuyer(
                $customer,
                $data['profile'],
                $data['buyer_tier'] ?? null,
                $request->user(),
                $data['notes'] ?? null
            );

            // Send approval email for granting profiles (does not roll back on failure).
            $emailStatus = $service->sendApprovalEmail($customer, $data['profile'], $request->user());

            SecurityEventService::log(
                'customer_approved', $customer->id,
                $request->ip(), $request->userAgent(),
                "Buyer approved by admin (profile: {$data['profile']})", 'info'
            );

            $message = "Buyer approved as {$data['profile']}.";
            if ($emailStatus['attempted']) {
                $message .= $emailStatus['sent']
                    ? ' Approval email sent.'
                    : ' Approval email failed to send — check mail config.';
            }

            return response()->json([
                'success' => true,
                'data'    => array_merge(
                    $this->formatSummary($customer->fresh()->load('approvedBy')),
                    ['approval_email' => $emailStatus]
                ),
                'message' => $message,
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

        $invite = $this->sendInvitationEmail($customer);

        SecurityEventService::log(
            'customer_invited', $customer->id,
            $request->ip(), $request->userAgent(),
            'Customer invitation sent by admin', 'info'
        );

        return response()->json([
            'success' => true,
            'data'    => array_merge($this->formatSummary($customer->fresh()), ['invitation_email' => $invite]),
            'message' => $invite['sent']
                ? 'Invitation email sent.'
                : 'Customer marked invited, but the email failed to send — check mail config and resend.',
        ]);
    }

    // ── POST /admin/customers/{id}/resend-invite ──────────────────────────────

    public function resendInvite(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        if (! in_array($customer->onboarding_status ?? 'active', ['invited', 'approved'], true)) {
            return response()->json(['message' => 'Customer does not have a pending invitation.'], 422);
        }

        $invite = $this->sendInvitationEmail($customer);

        SecurityEventService::log(
            'customer_invited', $customer->id,
            $request->ip(), $request->userAgent(),
            'Customer invitation resent by admin', 'info'
        );

        return response()->json([
            'success' => true,
            'data'    => ['invitation_email' => $invite],
            'message' => $invite['sent']
                ? 'Invitation email resent.'
                : 'Invitation email failed to send — check mail config.',
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

    /**
     * Generate a single-use set-password token and email the activation link.
     *
     * Sends synchronously and never throws — a mail failure is captured and
     * returned so callers can report it instead of failing the whole request
     * (the customer account + token are already persisted and can be resent).
     *
     * @return array{attempted: bool, sent: bool, error: ?string}
     */
    private function sendInvitationEmail(Customer $customer): array
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

        try {
            Mail::to($customer->email)->send(new CustomerInvitation($customer, $activationUrl));

            return ['attempted' => true, 'sent' => true, 'error' => null];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[customer_invitation_email_failed] Invitation email failed', [
                'event'       => 'customer_invitation_email_failed',
                'customer_id' => $customer->id,
                'email'       => $customer->email,
                'error'       => $e->getMessage(),
            ]);

            return ['attempted' => true, 'sent' => false, 'error' => $e->getMessage()];
        }
    }
}
