<?php

namespace App\Support;

use App\Models\Customer;

/**
 * CRM-8 — Shared serialisation of buyer-lifecycle fields.
 *
 * Used by the customer detail/list output and the customer-approvals queue so
 * both expose an identical buyer-lifecycle shape. Existing CRM-4 access fields
 * are intentionally NOT duplicated here — they are still emitted by their own
 * formatters.
 */
class CustomerLifecyclePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function fields(Customer $c): array
    {
        return [
            'buyer_tier'          => $c->buyer_tier ?? 'none',
            'verification_status' => $c->verification_status ?? 'not_started',
            'health_score'        => $c->health_score !== null ? (int) $c->health_score : null,
            'risk_level'          => $c->risk_level ?? 'unknown',
            'approved_by'         => $c->approved_by,
            'approved_by_name'    => $c->relationLoaded('approvedBy') ? $c->approvedBy?->name : null,
            'approved_at'         => $c->approved_at?->toIso8601String(),
            'approval_notes'      => $c->approval_notes,
            'rejection_reason'    => $c->rejection_reason,
        ];
    }
}
