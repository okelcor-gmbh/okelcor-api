<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * CRM-8 — Customer health score & risk band.
 *
 * Score is clamped to 0–100. Risk band:
 *   80–100 => low, 60–79 => medium, 40–59 => high, <40 => critical
 *
 * All external lookups are wrapped defensively — a query failure degrades the
 * affected factor to 0 rather than throwing.
 */
class CustomerHealthService
{
    private const PERSONAL_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'hotmail.com',
        'outlook.com', 'live.com', 'icloud.com', 'aol.com', 'gmx.com', 'gmx.de',
        'web.de', 'proton.me', 'protonmail.com', 'mail.com', 'yandex.com',
    ];

    /**
     * Compute (but do not persist) the health score and contributing factors.
     *
     * @return array{score:int, risk_level:string, factors:array<int,array{label:string, points:int}>}
     */
    public function calculate(Customer $customer): array
    {
        $factors = [];
        $add = function (string $label, int $points) use (&$factors) {
            if ($points !== 0) {
                $factors[] = ['label' => $label, 'points' => $points];
            }
        };

        // ── Positive signals ────────────────────────────────────────────────
        if ($this->hasVerification($customer, 'company_registration')
            || $customer->verification_status === 'verified') {
            $add('Verified company', 25);
        }

        if ($customer->vat_verified || $this->hasVerification($customer, 'vat_number')) {
            $add('VAT number verified', 15);
        }

        if ($this->hasVerification($customer, 'website') || $this->hasWebsite($customer)) {
            $add('Website verified/present', 10);
        }

        if ($this->hasAcceptedProposal($customer)) {
            $add('Accepted a proposal', 20);
        }

        $completedOrders = $this->completedOrderCount($customer);
        if ($completedOrders >= 1) {
            $add('Completed order', 20);
        }
        if ($completedOrders >= 2) {
            $add('Repeat orders', 20);
        }

        if ($this->profileComplete($customer)) {
            $add('Complete profile', 15);
        }

        // ── Negative signals ────────────────────────────────────────────────
        if ($customer->data_review_status === 'duplicate_suspected') {
            $add('Suspected duplicate', -15);
        }

        if ($this->isB2bWithPersonalEmail($customer)) {
            $add('Personal email for B2B', -10);
        }

        if (blank($customer->phone)) {
            $add('Missing phone', -10);
        }
        if (blank($customer->country)) {
            $add('Missing country', -10);
        }
        if (blank($customer->company_name)) {
            $add('Missing company', -10);
        }

        if (in_array($customer->onboarding_status, ['rejected', 'blocked'], true)
            || $customer->access_level === 'blocked'
            || $customer->verification_status === 'rejected'
            || filled($customer->rejection_reason)) {
            $add('Rejected / blocked history', -25);
        }

        $score = (int) max(0, min(100, array_sum(array_column($factors, 'points'))));

        return [
            'score'      => $score,
            'risk_level' => $this->band($score),
            'factors'    => $factors,
        ];
    }

    /**
     * Recalculate by customer email — convenience for call sites that only
     * have an Order (which links to a Customer by email, not a FK). No-ops
     * silently when there's no matching onboarded Customer (guest/eBay
     * orders) or on any failure — health recompute must never block an
     * order-paid or proposal-accepted flow.
     */
    public function recalculateForEmail(string $email, ?AdminUser $admin = null): void
    {
        try {
            $customer = Customer::where('email', $email)->first();
            if ($customer) {
                $this->recalculateAndSave($customer, $admin);
            }
        } catch (\Throwable) {
            // Best-effort — never block the caller's real work.
        }
    }

    /**
     * Recompute, persist, and log a risk_level_changed timeline event when the
     * band moves. Returns the same payload as calculate().
     */
    public function recalculateAndSave(Customer $customer, ?AdminUser $admin = null): array
    {
        $result   = $this->calculate($customer);
        $oldRisk  = $customer->risk_level;

        $customer->update([
            'health_score' => $result['score'],
            'risk_level'   => $result['risk_level'],
        ]);

        if ($oldRisk !== $result['risk_level']) {
            CustomerTimelineService::record(
                $customer->id,
                'risk_level_changed',
                'Risk level changed',
                "Risk level changed from {$oldRisk} to {$result['risk_level']} (health score {$result['score']}).",
                ['from' => $oldRisk, 'to' => $result['risk_level'], 'score' => $result['score']],
                $admin?->id
            );
        }

        return $result;
    }

    public function band(int $score): string
    {
        return match (true) {
            $score >= 80 => 'low',
            $score >= 60 => 'medium',
            $score >= 40 => 'high',
            default      => 'critical',
        };
    }

    // ── Signal helpers ────────────────────────────────────────────────────────

    private function hasVerification(Customer $customer, string $type): bool
    {
        try {
            return $customer->verifications()
                ->where('type', $type)
                ->where('status', 'verified')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasWebsite(Customer $customer): bool
    {
        // No dedicated website column on customers — rely on verification rows.
        return false;
    }

    private function hasAcceptedProposal(Customer $customer): bool
    {
        try {
            return DB::table('quote_requests')
                ->where(function ($q) use ($customer) {
                    $q->where('customer_id', $customer->id)
                        ->orWhere('email', $customer->email);
                })
                ->where('proposal_status', 'accepted')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function completedOrderCount(Customer $customer): int
    {
        try {
            return DB::table('orders')
                ->where('customer_email', $customer->email)
                ->where('payment_status', 'paid')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function profileComplete(Customer $customer): bool
    {
        return filled($customer->phone)
            && filled($customer->country)
            && filled($customer->company_name)
            && filled($customer->email);
    }

    private function isB2bWithPersonalEmail(Customer $customer): bool
    {
        if ($customer->customer_type !== 'b2b' || blank($customer->email)) {
            return false;
        }

        $domain = strtolower(trim(substr(strrchr($customer->email, '@') ?: '', 1)));

        return $domain !== '' && in_array($domain, self::PERSONAL_EMAIL_DOMAINS, true);
    }
}
