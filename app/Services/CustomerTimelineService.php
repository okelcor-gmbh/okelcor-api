<?php

namespace App\Services;

use App\Models\CustomerTimelineEvent;
use Illuminate\Support\Facades\Log;

/**
 * CRM-8 — Records buyer-lifecycle timeline events.
 *
 * Writes are best-effort and never throw into the caller: a timeline write
 * failure must not break an approval/conversion flow.
 *
 * Canonical event_type values:
 *   customer_created, lead_converted, proposal_accepted, access_profile_applied,
 *   customer_approved, customer_rejected, tier_changed, verification_updated,
 *   risk_level_changed, customer_blocked, customer_unblocked, access_requested,
 *   access_request_approved, access_request_rejected
 */
class CustomerTimelineService
{
    public static function record(
        int $customerId,
        string $eventType,
        string $title,
        ?string $description = null,
        array $metadata = [],
        ?int $adminUserId = null
    ): void {
        try {
            CustomerTimelineEvent::create([
                'customer_id'   => $customerId,
                'admin_user_id' => $adminUserId,
                'event_type'    => $eventType,
                'title'         => $title,
                'description'   => $description,
                'metadata'      => $metadata ?: null,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CustomerTimelineEvent write failed', [
                'customer_id' => $customerId,
                'event_type'  => $eventType,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
