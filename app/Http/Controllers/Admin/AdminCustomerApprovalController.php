<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerApprovalService;
use App\Services\CustomerHealthService;
use App\Support\CustomerLifecyclePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM-8 — Buyer approval queue, access profiles, tier/risk, and timeline.
 *
 * Reads require permission:customers.view; mutations require permission:customers.manage
 * (enforced in routes/api.php).
 *
 * Note: the buyer approve/reject actions live on AdminCustomerController
 * (POST customers/{id}/approve|reject) to keep the existing onboarding routes,
 * enhanced backward-compatibly. Everything else lives here.
 */
class AdminCustomerApprovalController extends Controller
{
    public function __construct(
        private readonly CustomerApprovalService $approval,
        private readonly CustomerHealthService $health,
    ) {}

    // ── GET /admin/customer-approvals ────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'              => ['nullable', 'string'],
            'verification_status' => ['nullable', Rule::in(['not_started', 'pending_review', 'verified', 'rejected'])],
            'risk_level'          => ['nullable', Rule::in(['low', 'medium', 'high', 'critical', 'unknown'])],
            'buyer_tier'          => ['nullable', Rule::in(CustomerApprovalService::TIERS)],
            'market_region'       => ['nullable', Rule::in(['eu', 'africa', 'middle_east', 'global', 'unknown'])],
            'q'                   => ['nullable', 'string', 'max:200'],
            'per_page'            => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Customer::query()->with('approvedBy')->orderByDesc('created_at');

        // status maps onto the buyer-lifecycle queues
        switch ($request->query('status')) {
            case 'pending_review':
                $query->where(function ($q) {
                    $q->where('onboarding_status', 'pending_review')
                        ->orWhere('verification_status', 'pending_review');
                });
                break;
            case 'approved':
                $query->where('access_level', 'approved_buyer');
                break;
            case 'wholesale':
                $query->where('access_level', 'wholesale_buyer');
                break;
            case 'restricted':
                $query->where('access_level', 'restricted');
                break;
            case 'blocked':
                $query->where('access_level', 'blocked');
                break;
            case 'high_risk':
                $query->whereIn('risk_level', ['high', 'critical']);
                break;
        }

        if ($request->filled('verification_status')) {
            $query->where('verification_status', $request->query('verification_status'));
        }
        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->query('risk_level'));
        }
        if ($request->filled('buyer_tier')) {
            $query->where('buyer_tier', $request->query('buyer_tier'));
        }
        if ($request->filled('market_region')) {
            $query->where('market_region', $request->query('market_region'));
        }
        if ($request->filled('q')) {
            $term = '%' . $request->query('q') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('company_name', 'like', $term);
            });
        }

        $paginated = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($paginated->items())->map(fn (Customer $c) => $this->row($c))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'cards'        => $this->summaryCards(),
            ],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/customers/{id}/timeline ───────────────────────────────────

    public function timeline(int $id): JsonResponse
    {
        $customer = Customer::with('timelineEvents.admin')->findOrFail($id);

        return response()->json([
            'data' => $customer->timelineEvents->map(fn ($e) => [
                'id'          => $e->id,
                'event_type'  => $e->event_type,
                'title'       => $e->title,
                'description' => $e->description,
                'metadata'    => $e->metadata,
                'admin'       => $e->admin?->name,
                'created_at'  => $e->created_at?->toIso8601String(),
            ])->values(),
            'meta'    => ['count' => $customer->timelineEvents->count()],
            'message' => 'success',
        ]);
    }

    // ── POST /admin/customers/{id}/approval-profile ──────────────────────────

    public function applyProfile(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'profile' => ['required', Rule::in(CustomerApprovalService::PROFILES)],
            'notes'   => ['nullable', 'string', 'max:2000'],
        ]);

        $customer = $this->approval->applyApprovalProfile(
            $customer, $data['profile'], $request->user(), $data['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'data'    => $this->detail($customer->fresh()->load('approvedBy')),
            'preview' => $this->approval->profilePreview($data['profile']),
            'message' => "Access profile '{$data['profile']}' applied.",
        ]);
    }

    // ── POST /admin/customers/{id}/set-tier ──────────────────────────────────

    public function setTier(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'buyer_tier' => ['required', Rule::in(CustomerApprovalService::TIERS)],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ]);

        $customer = $this->approval->setTier($customer, $data['buyer_tier'], $request->user(), $data['notes'] ?? null);

        return response()->json([
            'success' => true,
            'data'    => $this->detail($customer->load('approvedBy')),
            'message' => "Buyer tier set to {$data['buyer_tier']}.",
        ]);
    }

    // ── POST /admin/customers/{id}/risk ──────────────────────────────────────

    public function setRisk(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'risk_level' => ['required', Rule::in(['low', 'medium', 'high', 'critical', 'unknown'])],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ]);

        $customer = $this->approval->setRisk($customer, $data['risk_level'], $request->user(), $data['notes'] ?? null);

        return response()->json([
            'success' => true,
            'data'    => $this->detail($customer->load('approvedBy')),
            'message' => "Risk level set to {$data['risk_level']}.",
        ]);
    }

    // ── POST /admin/customers/{id}/health/recalculate ────────────────────────

    public function recalculateHealth(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $result = $this->health->recalculateAndSave($customer, $request->user());

        return response()->json([
            'success' => true,
            'data'    => array_merge($this->detail($customer->fresh()->load('approvedBy')), [
                'health_factors' => $result['factors'],
            ]),
            'message' => "Health score recalculated: {$result['score']} ({$result['risk_level']} risk).",
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Compact row for the approvals queue. */
    private function row(Customer $c): array
    {
        return array_merge([
            'id'                => $c->id,
            'first_name'        => $c->first_name,
            'last_name'         => $c->last_name,
            'full_name'         => trim($c->first_name . ' ' . $c->last_name),
            'email'             => $c->email,
            'company_name'      => $c->company_name,
            'country'           => $c->country,
            'customer_segment'  => $c->customer_segment ?? 'unknown',
            'access_level'      => $c->access_level ?? 'inquiry_only',
            'market_region'     => $c->market_region ?? 'unknown',
            'onboarding_status' => $c->onboarding_status ?? 'active',
            'is_active'         => (bool) $c->is_active,
            'created_at'        => $c->created_at?->toIso8601String(),
        ], CustomerLifecyclePresenter::fields($c));
    }

    /** Full lifecycle detail (access flags + lifecycle fields). */
    private function detail(Customer $c): array
    {
        return array_merge($this->row($c), [
            'approved_for_quotes'            => (bool) ($c->approved_for_quotes ?? true),
            'approved_for_checkout'          => (bool) ($c->approved_for_checkout ?? false),
            'approved_for_documents'         => (bool) ($c->approved_for_documents ?? false),
            'approved_for_wholesale_pricing' => (bool) ($c->approved_for_wholesale_pricing ?? false),
        ]);
    }

    /** Card counters shown at the top of the approvals page. */
    private function summaryCards(): array
    {
        return [
            'pending_approvals' => Customer::where(function ($q) {
                $q->where('onboarding_status', 'pending_review')
                    ->orWhere('verification_status', 'pending_review');
            })->count(),
            'verified_buyers'      => Customer::where('verification_status', 'verified')->count(),
            'high_risk'            => Customer::whereIn('risk_level', ['high', 'critical'])->count(),
            'access_requests_pending' => \App\Models\CustomerAccessRequest::where('status', 'pending')->count(),
        ];
    }
}
