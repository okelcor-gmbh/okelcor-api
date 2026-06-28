<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerVerification;
use App\Services\CustomerHealthService;
use App\Services\CustomerNotifier;
use App\Services\CustomerTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM-8 — Customer verification records.
 *
 *   GET   /admin/customers/{id}/verifications              (customers.view)
 *   POST  /admin/customers/{id}/verifications              (customers.manage)
 *   PATCH /admin/customers/{id}/verifications/{verificationId} (customers.manage)
 */
class AdminCustomerVerificationController extends Controller
{
    private const TYPES = [
        'company_registration', 'vat_number', 'website',
        'import_license', 'business_address', 'other',
    ];

    public function index(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        return response()->json([
            'data'    => $customer->verifications->map(fn ($v) => $this->format($v))->values(),
            'meta'    => ['count' => $customer->verifications->count()],
            'message' => 'success',
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'type'   => ['required', Rule::in(self::TYPES)],
            'value'  => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['not_submitted', 'pending_review', 'verified', 'rejected'])],
            'notes'  => ['nullable', 'string', 'max:2000'],
        ]);

        $status = $data['status'] ?? 'pending_review';

        $verification = CustomerVerification::create([
            'customer_id' => $customer->id,
            'type'        => $data['type'],
            'value'       => $data['value'] ?? null,
            'status'      => $status,
            'notes'       => $data['notes'] ?? null,
            'reviewed_by' => in_array($status, ['verified', 'rejected'], true) ? $request->user()?->id : null,
            'reviewed_at' => in_array($status, ['verified', 'rejected'], true) ? now() : null,
        ]);

        $this->afterChange($customer, $verification, $request, 'added');

        return response()->json([
            'data'    => $this->format($verification),
            'message' => 'Verification record added.',
        ], 201);
    }

    public function update(Request $request, int $id, int $verificationId): JsonResponse
    {
        $customer     = Customer::findOrFail($id);
        $verification = CustomerVerification::where('customer_id', $customer->id)->findOrFail($verificationId);

        $data = $request->validate([
            'type'   => ['sometimes', Rule::in(self::TYPES)],
            'value'  => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['not_submitted', 'pending_review', 'verified', 'rejected'])],
            'notes'  => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('status', $data)
            && in_array($data['status'], ['verified', 'rejected'], true)
            && $data['status'] !== $verification->status) {
            $data['reviewed_by'] = $request->user()?->id;
            $data['reviewed_at'] = now();
        }

        $verification->update($data);

        $this->afterChange($customer, $verification->fresh(), $request, 'updated');

        return response()->json([
            'data'    => $this->format($verification->fresh()),
            'message' => 'Verification record updated.',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Roll the customer's overall verification_status up from its records,
     * log a timeline event, and recompute health (verification feeds scoring).
     */
    private function afterChange(Customer $customer, CustomerVerification $v, Request $request, string $verb): void
    {
        CustomerTimelineService::record(
            $customer->id,
            'verification_updated',
            'Verification ' . $verb,
            "Verification '{$v->type}' {$verb} — status: {$v->status}.",
            ['type' => $v->type, 'status' => $v->status, 'verification_id' => $v->id],
            $request->user()?->id
        );

        $this->rollUpVerificationStatus($customer);

        try {
            app(CustomerHealthService::class)->recalculateAndSave($customer->fresh(), $request->user());
        } catch (\Throwable) {
            // Health recompute is best-effort; never block a verification change.
        }

        $this->notifyVerificationOutcome($customer, $v);
    }

    /**
     * In-app twin for a verification reaching a terminal outcome. In-app only
     * (no customer email exists for this today). Dedupe (stage = status) keeps
     * one row per outcome per verification record.
     */
    private function notifyVerificationOutcome(Customer $customer, CustomerVerification $v): void
    {
        if (! in_array($v->status, ['verified', 'rejected'], true)) {
            return;
        }

        $label = match ($v->type) {
            'company_registration' => 'company registration',
            'vat_number'           => 'VAT number',
            'website'              => 'website',
            'import_license'       => 'import licence',
            'business_address'     => 'business address',
            default                => 'business details',
        };

        [$title, $body, $severity] = $v->status === 'verified'
            ? ["Your {$label} has been verified", "Thanks — we've verified your {$label}.", 'success']
            : ["We couldn't verify your {$label}", "We were unable to verify your {$label}. Please review the details or contact us.", 'warning'];

        CustomerNotifier::notify(
            $customer,
            'verification_update',
            $title,
            $body,
            [
                'severity'     => $severity,
                'action_url'   => '/account/company',
                'related_type' => 'verification',
                'related_id'   => $v->id,
                'metadata'     => ['stage' => $v->status, 'verification_type' => $v->type],
            ]
        );
    }

    private function rollUpVerificationStatus(Customer $customer): void
    {
        $statuses = $customer->verifications()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $rollUp = match (true) {
            $statuses->contains('verified')       => 'verified',
            $statuses->contains('pending_review') => 'pending_review',
            $statuses->contains('rejected')       => 'rejected',
            default                               => $customer->verification_status ?? 'not_started',
        };

        if ($rollUp !== $customer->verification_status) {
            $customer->update(['verification_status' => $rollUp]);
        }
    }

    private function format(CustomerVerification $v): array
    {
        return [
            'id'          => $v->id,
            'type'        => $v->type,
            'value'       => $v->value,
            'status'      => $v->status,
            'notes'       => $v->notes,
            'reviewed_by' => $v->reviewed_by,
            'reviewed_at' => $v->reviewed_at?->toIso8601String(),
            'created_at'  => $v->created_at?->toIso8601String(),
            'updated_at'  => $v->updated_at?->toIso8601String(),
        ];
    }
}
