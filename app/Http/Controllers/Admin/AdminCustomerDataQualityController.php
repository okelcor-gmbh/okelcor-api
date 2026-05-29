<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerDataQualityService;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminCustomerDataQualityController extends Controller
{
    public function __construct(private CustomerDataQualityService $qualityService) {}

    // ── GET /admin/customers/data-quality/summary ────────────────────────────

    public function summary(): JsonResponse
    {
        $base = DB::table('customers');

        return response()->json([
            'data' => [
                'total_customers'          => (clone $base)->count(),
                'clean_count'              => (clone $base)->where('data_review_status', 'clean')->count(),
                'needs_review_count'       => (clone $base)->where('data_review_status', 'needs_review')->count(),
                'duplicate_suspected_count' => (clone $base)->where('data_review_status', 'duplicate_suspected')->count(),
                'incomplete_count'         => (clone $base)->whereRaw("JSON_CONTAINS(data_quality_flags, '\"incomplete_profile\"')")->count(),
                'personal_email_count'     => (clone $base)->whereRaw("JSON_CONTAINS(data_quality_flags, '\"personal_email_for_b2b\"')")->count(),
                'not_yet_scored'           => (clone $base)->whereNull('data_quality_score')->count(),
            ],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/customers/data-quality/issues ─────────────────────────────

    public function issues(Request $request): JsonResponse
    {
        $request->validate([
            'review_status'  => ['nullable', 'in:clean,needs_review,duplicate_suspected,merged,ignored'],
            'flag'           => ['nullable', 'string', 'max:60'],
            'min_score'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_score'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'duplicate_only' => ['nullable', 'boolean'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Customer::orderBy('data_quality_score')->orderByDesc('created_at');

        if ($request->filled('review_status')) {
            $query->where('data_review_status', $request->review_status);
        } else {
            // Default: exclude clean + ignored + merged to show actionable issues
            $query->whereNotIn('data_review_status', ['clean', 'ignored', 'merged']);
        }

        if ($request->boolean('duplicate_only')) {
            $query->where('data_review_status', 'duplicate_suspected');
        }

        if ($request->filled('flag')) {
            $query->whereRaw("JSON_CONTAINS(data_quality_flags, ?)", ['"' . $request->flag . '"']);
        }

        if ($request->filled('min_score')) {
            $query->where('data_quality_score', '>=', $request->integer('min_score'));
        }

        if ($request->filled('max_score')) {
            $query->where('data_quality_score', '<=', $request->integer('max_score'));
        }

        $paginated = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data'    => $paginated->map(fn ($c) => $this->formatQualityRow($c))->values(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    // ── POST /admin/customers/{id}/data-quality/recalculate ──────────────────

    public function recalculate(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $updated  = $this->qualityService->computeAndPersist($customer);

        Log::info('[customer_data_quality_recalculated] Quality recalculated', [
            'event'       => 'customer_data_quality_recalculated',
            'customer_id' => $customer->id,
            'score'       => $updated->data_quality_score,
            'status'      => $updated->data_review_status,
            'by_admin'    => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatQualityRow($updated),
            'message' => 'Data quality recalculated.',
        ]);
    }

    // ── POST /admin/customers/{id}/data-quality/mark-clean ───────────────────

    public function markClean(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->update([
            'data_review_status'   => 'clean',
            'possible_duplicate_of' => null,
            'duplicate_group_id'   => null,
        ]);

        Log::info('[customer_marked_clean] Admin marked customer as clean', [
            'event'       => 'customer_marked_clean',
            'customer_id' => $customer->id,
            'by_admin'    => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatQualityRow($customer->fresh()),
            'message' => 'Customer marked as clean.',
        ]);
    }

    // ── POST /admin/customers/{id}/data-quality/ignore-duplicate ─────────────

    public function ignoreDuplicate(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->update([
            'data_review_status'   => 'ignored',
            'possible_duplicate_of' => null,
            'duplicate_group_id'   => null,
        ]);

        Log::info('[customer_duplicate_ignored] Admin ignored duplicate flag', [
            'event'       => 'customer_duplicate_ignored',
            'customer_id' => $customer->id,
            'by_admin'    => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatQualityRow($customer->fresh()),
            'message' => 'Duplicate flag ignored.',
        ]);
    }

    // ── POST /admin/customers/{id}/data-quality/link-duplicate ───────────────

    public function linkDuplicate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'possible_duplicate_of' => ['required', 'integer', 'exists:customers,id', 'different:id'],
        ]);

        $customer  = Customer::findOrFail($id);
        $duplicate = Customer::findOrFail($data['possible_duplicate_of']);

        // Assign both customers a shared group ID so they can be queried together
        $groupId = $customer->duplicate_group_id
            ?? $duplicate->duplicate_group_id
            ?? 'dup_' . Str::random(12);

        $customer->update([
            'possible_duplicate_of' => $duplicate->id,
            'data_review_status'    => 'duplicate_suspected',
            'duplicate_group_id'    => $groupId,
        ]);

        $duplicate->update([
            'duplicate_group_id' => $groupId,
        ]);

        Log::info('[customer_duplicate_linked] Admin linked duplicate customers', [
            'event'          => 'customer_duplicate_linked',
            'customer_id'    => $customer->id,
            'duplicate_of'   => $duplicate->id,
            'group_id'       => $groupId,
            'by_admin'       => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatQualityRow($customer->fresh()),
            'message' => 'Duplicate link established.',
        ]);
    }

    // ── POST /admin/customers/{id}/data-quality/merge-preview ────────────────

    public function mergePreview(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'merge_into' => ['required', 'integer', 'exists:customers,id', 'different:id'],
        ]);

        $source = Customer::with('addresses')->findOrFail($id);
        $target = Customer::with('addresses')->findOrFail($data['merge_into']);

        // Build field-by-field comparison — no changes written
        $fields = ['first_name', 'last_name', 'email', 'phone', 'country',
                   'company_name', 'vat_number', 'customer_type', 'onboarding_status',
                   'customer_segment', 'access_level', 'market_region'];

        $comparison = [];
        foreach ($fields as $field) {
            $comparison[$field] = [
                'source_value' => $source->$field,
                'target_value' => $target->$field,
                'conflict'     => $source->$field !== $target->$field
                                   && $source->$field !== null
                                   && $target->$field !== null,
            ];
        }

        $quoteCount    = \App\Models\QuoteRequest::where('customer_id', $source->id)->count();
        $orderCount    = \App\Models\Order::where('customer_email', $source->email)->count();
        $addressCount  = $source->addresses->count();

        return response()->json([
            'data' => [
                'source_id'         => $source->id,
                'target_id'         => $target->id,
                'field_comparison'  => $comparison,
                'source_has'        => [
                    'quotes'    => $quoteCount,
                    'orders'    => $orderCount,
                    'addresses' => $addressCount,
                ],
                'conflicts'         => collect($comparison)->where('conflict', true)->keys()->values(),
                'merge_safe'        => collect($comparison)->where('conflict', true)->isEmpty(),
                'warning'           => 'This is a preview only. No merge has been performed. Merging requires manual DB action.',
            ],
            'message' => 'Merge preview generated — no changes made.',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formatQualityRow(Customer $c): array
    {
        return [
            'id'                     => $c->id,
            'full_name'              => $c->first_name . ' ' . $c->last_name,
            'email'                  => $c->email,
            'company_name'           => $c->company_name,
            'customer_type'          => $c->customer_type,
            'country'                => $c->country,
            'onboarding_status'      => $c->onboarding_status ?? 'active',
            // Quality
            'data_quality_score'     => $c->data_quality_score,
            'data_quality_flags'     => $c->data_quality_flags ?? [],
            'data_review_status'     => $c->data_review_status ?? 'needs_review',
            'normalized_email'       => $c->normalized_email,
            'normalized_company_name' => $c->normalized_company_name,
            'possible_duplicate_of'  => $c->possible_duplicate_of,
            'duplicate_group_id'     => $c->duplicate_group_id,
            'created_at'             => $c->created_at?->toIso8601String(),
        ];
    }
}
