<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Customer;

/**
 * CRM-8 — Buyer approval & access-profile management.
 *
 * Sits ABOVE the CRM-4 access fields. Applying a profile is the single,
 * auditable way to set access_level + the four approved_for_* flags
 * together, so admins never have to toggle individual flags by hand.
 *
 * Profiles:
 *   inquiry_only    — quotes only, no checkout/documents/wholesale
 *   approved_buyer  — checkout + documents, bronze tier
 *   wholesale_buyer — checkout + documents + wholesale pricing, silver tier
 *   restricted      — quotes only (explicitly held back)
 *   blocked         — everything off; tokens revoked, account deactivated
 */
class CustomerApprovalService
{
    public const PROFILES = [
        'inquiry_only',
        'approved_buyer',
        'wholesale_buyer',
        'restricted',
        'blocked',
    ];

    public const TIERS = ['bronze', 'silver', 'gold', 'platinum', 'vip', 'none'];

    /**
     * The access changes a profile applies. buyer_tier is only present when the
     * profile dictates a default tier (it can still be overridden separately).
     *
     * @return array<string, mixed>
     */
    public function profileChanges(string $profile): array
    {
        return match ($profile) {
            'inquiry_only' => [
                'access_level'                   => 'inquiry_only',
                'approved_for_quotes'            => true,
                'approved_for_checkout'          => false,
                'approved_for_documents'         => false,
                'approved_for_wholesale_pricing' => false,
            ],
            'approved_buyer' => [
                'access_level'                   => 'approved_buyer',
                'approved_for_quotes'            => true,
                'approved_for_checkout'          => true,
                'approved_for_documents'         => true,
                'approved_for_wholesale_pricing' => false,
                'buyer_tier'                     => 'bronze',
            ],
            'wholesale_buyer' => [
                'access_level'                   => 'wholesale_buyer',
                'approved_for_quotes'            => true,
                'approved_for_checkout'          => true,
                'approved_for_documents'         => true,
                'approved_for_wholesale_pricing' => true,
                'buyer_tier'                     => 'silver',
            ],
            'restricted' => [
                'access_level'                   => 'restricted',
                'approved_for_quotes'            => true,
                'approved_for_checkout'          => false,
                'approved_for_documents'         => false,
                'approved_for_wholesale_pricing' => false,
            ],
            'blocked' => [
                'access_level'                   => 'blocked',
                'approved_for_quotes'            => false,
                'approved_for_checkout'          => false,
                'approved_for_documents'         => false,
                'approved_for_wholesale_pricing' => false,
            ],
            default => throw new \InvalidArgumentException("Unknown approval profile: {$profile}"),
        };
    }

    /**
     * A frontend-friendly preview of what a profile changes, without applying it.
     *
     * @return array<string, mixed>
     */
    public function profilePreview(string $profile): array
    {
        $c = $this->profileChanges($profile);

        return [
            'profile'                        => $profile,
            'access_level'                   => $c['access_level'],
            'approved_for_quotes'            => $c['approved_for_quotes'],
            'approved_for_checkout'          => $c['approved_for_checkout'],
            'approved_for_documents'         => $c['approved_for_documents'],
            'approved_for_wholesale_pricing' => $c['approved_for_wholesale_pricing'],
            'buyer_tier'                     => $c['buyer_tier'] ?? null, // null => tier unchanged
        ];
    }

    /**
     * Apply an access profile to a customer.
     *
     * Handles block/unblock side-effects (token revocation, is_active) and logs
     * the appropriate timeline events. Returns the refreshed customer.
     */
    public function applyApprovalProfile(
        Customer $customer,
        string $profile,
        ?AdminUser $admin = null,
        ?string $notes = null
    ): Customer {
        $changes   = $this->profileChanges($profile);
        $wasBlocked = $customer->access_level === 'blocked';

        $customer->update($changes);

        if ($profile === 'blocked') {
            // Revoke active sessions and deactivate the account.
            $customer->tokens()->delete();
            $customer->update(['is_active' => false]);

            CustomerTimelineService::record(
                $customer->id,
                'customer_blocked',
                'Customer blocked',
                $notes ?: 'Buyer blocked — all access revoked and sessions terminated.',
                ['profile' => $profile],
                $admin?->id
            );
        } elseif ($wasBlocked) {
            CustomerTimelineService::record(
                $customer->id,
                'customer_unblocked',
                'Customer unblocked',
                $notes ?: "Buyer unblocked — profile changed to {$profile}.",
                ['profile' => $profile],
                $admin?->id
            );
        }

        CustomerTimelineService::record(
            $customer->id,
            'access_profile_applied',
            'Access profile applied',
            $notes ?: "Access profile '{$profile}' applied.",
            ['profile' => $profile, 'changes' => $changes],
            $admin?->id
        );

        return $customer->fresh();
    }

    /**
     * Approve a buyer: apply a profile, stamp approval audit fields, optionally
     * override the tier, and advance onboarding when still pending.
     */
    public function approveBuyer(
        Customer $customer,
        string $profile,
        ?string $buyerTier = null,
        ?AdminUser $admin = null,
        ?string $notes = null
    ): Customer {
        $this->applyApprovalProfile($customer, $profile, $admin, $notes);
        $customer->refresh();

        $update = [
            'approved_by'      => $admin?->id,
            'approved_at'      => now(),
            'approval_notes'   => $notes,
            'rejection_reason' => null,
        ];

        if ($buyerTier !== null) {
            $update['buyer_tier'] = $buyerTier;
        }

        // Advance onboarding so the admin can then invite — without bypassing
        // the password/invite flow (we never force is_active here).
        if (in_array($customer->onboarding_status, ['pending_review', 'rejected'], true)) {
            $update['onboarding_status'] = 'approved';
        }

        $customer->update($update);

        CustomerTimelineService::record(
            $customer->id,
            'customer_approved',
            'Customer approved',
            $notes ?: "Buyer approved as '{$profile}'"
                . ($buyerTier ? " (tier: {$buyerTier})." : '.'),
            ['profile' => $profile, 'buyer_tier' => $buyerTier ?? $customer->buyer_tier],
            $admin?->id
        );

        return $customer->fresh();
    }

    /**
     * Reject a buyer. Does not delete data; records the reason and timeline.
     */
    public function rejectBuyer(Customer $customer, string $reason, ?AdminUser $admin = null): Customer
    {
        $customer->update([
            'verification_status' => 'rejected',
            'rejection_reason'    => $reason,
            'approved_by'         => null,
            'approved_at'         => null,
        ]);

        CustomerTimelineService::record(
            $customer->id,
            'customer_rejected',
            'Customer rejected',
            $reason,
            ['reason' => $reason],
            $admin?->id
        );

        return $customer->fresh();
    }

    /**
     * Change the buyer tier and log a tier_changed event.
     */
    public function setTier(Customer $customer, string $tier, ?AdminUser $admin = null, ?string $notes = null): Customer
    {
        $from = $customer->buyer_tier;
        $customer->update(['buyer_tier' => $tier]);

        CustomerTimelineService::record(
            $customer->id,
            'tier_changed',
            'Buyer tier changed',
            $notes ?: "Buyer tier changed from {$from} to {$tier}.",
            ['from' => $from, 'to' => $tier],
            $admin?->id
        );

        return $customer->fresh();
    }

    /**
     * Set the risk level manually and log a risk_level_changed event.
     */
    public function setRisk(Customer $customer, string $riskLevel, ?AdminUser $admin = null, ?string $notes = null): Customer
    {
        $from = $customer->risk_level;
        $customer->update(['risk_level' => $riskLevel]);

        if ($from !== $riskLevel) {
            CustomerTimelineService::record(
                $customer->id,
                'risk_level_changed',
                'Risk level changed',
                $notes ?: "Risk level changed from {$from} to {$riskLevel} (manual override).",
                ['from' => $from, 'to' => $riskLevel, 'manual' => true],
                $admin?->id
            );
        }

        return $customer->fresh();
    }
}
