<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Computes data quality scores and detects potential duplicates for customer records.
 *
 * Design principles:
 * - Never deletes or merges records automatically
 * - Never blocks customer access
 * - Only provides signals for admin review
 * - Scoring is generous — imperfect data is not penalised harshly
 */
class CustomerDataQualityService
{
    private const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com',
        'icloud.com', 'aol.com', 'mail.com', 'protonmail.com', 'zoho.com',
        'gmx.com', 'gmx.net', 'web.de', 'yahoo.co.uk', 'yahoo.de',
    ];

    // Company name suffixes stripped during normalization
    private const COMPANY_SUFFIXES = [
        ' limited', ' ltd', ' gmbh', ' llc', ' inc', ' incorporated',
        ' bv', ' b.v', ' sarl', ' s.a.r.l', ' s.a', ' sa',
        ' co', ' company', ' corp', ' corporation',
        ' ug', ' ag', ' kg', ' ohg', ' gbr',
    ];

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Compute quality score + flags and persist them to the customer record.
     */
    public function computeAndPersist(Customer $customer): Customer
    {
        $normalized = $this->buildNormalized($customer);
        [$score, $flags] = $this->buildScore($customer);
        $duplicates   = $this->findDuplicates($customer);
        $dupFlags     = $this->buildDuplicateFlags($duplicates);

        $allFlags    = array_values(array_unique(array_merge($flags, $dupFlags)));
        $finalScore  = max(0, min(100, $score));
        $reviewStatus = $this->determineReviewStatus($finalScore, $allFlags, $duplicates);

        $possibleDuplicateOf = $duplicates['exact_email']?->id
            ?? $duplicates['exact_phone']?->id
            ?? $duplicates['same_company_country']?->id
            ?? null;

        // Keep existing manual overrides (ignored / merged)
        $currentStatus = $customer->data_review_status;
        if (in_array($currentStatus, ['ignored', 'merged'], true)) {
            $reviewStatus = $currentStatus;
        }

        $customer->update(array_merge($normalized, [
            'data_quality_score'  => $finalScore,
            'data_quality_flags'  => $allFlags ?: null,
            'possible_duplicate_of' => $possibleDuplicateOf,
            'data_review_status'  => $reviewStatus,
        ]));

        return $customer->fresh();
    }

    /**
     * Normalize email and company name (without persisting).
     */
    public function buildNormalized(Customer $customer): array
    {
        return [
            'normalized_email'        => strtolower(trim($customer->email ?? '')),
            'normalized_company_name' => $customer->company_name
                ? $this->normalizeCompany($customer->company_name)
                : null,
        ];
    }

    /**
     * Find potential duplicate customers. Returns an associative array of
     * [ confidence_level => Customer|null ] or empty if none found.
     *
     * @return array{exact_email: ?Customer, exact_phone: ?Customer, same_company_country: ?Customer}
     */
    public function findDuplicates(Customer $customer): array
    {
        $result = [
            'exact_email'          => null,
            'exact_phone'          => null,
            'same_company_country' => null,
        ];

        $normalizedEmail = strtolower(trim($customer->email ?? ''));

        // Exact email match (highest confidence — emails must be unique, so this catches
        // import duplicates or casing mismatches in old data)
        if ($normalizedEmail) {
            $result['exact_email'] = Customer::where('id', '!=', $customer->id)
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])
                ->first();
        }

        // Exact phone match (high confidence)
        if (! empty($customer->phone) && strlen(trim($customer->phone)) >= 7) {
            $cleanPhone = preg_replace('/[\s\-\(\)\+]/', '', $customer->phone);
            $result['exact_phone'] = Customer::where('id', '!=', $customer->id)
                ->whereNotNull('phone')
                ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','') = ?", [$cleanPhone])
                ->first();
        }

        // Same normalized company + country (medium confidence)
        if (! empty($customer->company_name) && ! empty($customer->country)) {
            $normCompany = $this->normalizeCompany($customer->company_name);
            if (strlen($normCompany) >= 3) {
                $result['same_company_country'] = Customer::where('id', '!=', $customer->id)
                    ->where('country', $customer->country)
                    ->whereNotNull('normalized_company_name')
                    ->where('normalized_company_name', $normCompany)
                    ->first();
            }
        }

        return array_filter($result);  // remove nulls
    }

    /**
     * Normalize a company name: lowercase, strip punctuation, remove suffix noise.
     */
    public function normalizeCompany(string $name): string
    {
        $n = strtolower(trim($name));

        // Strip punctuation (keep alphanumeric + spaces)
        $n = preg_replace('/[^\w\s]/', ' ', $n);
        $n = preg_replace('/\s+/', ' ', trim($n));

        // Remove trailing legal suffixes (longest first to avoid partial match)
        foreach (self::COMPANY_SUFFIXES as $suffix) {
            if (str_ends_with($n, $suffix)) {
                $n = substr($n, 0, -strlen($suffix));
                $n = trim($n);
                break;  // only strip one suffix level
            }
        }

        return trim($n);
    }

    // ── Scoring ──────────────────────────────────────────────────────────────

    /** @return array{int, list<string>} */
    private function buildScore(Customer $customer): array
    {
        $score = 0;
        $flags = [];

        // Email (always present — structural requirement)
        $score += 20;

        // Phone
        if (! empty($customer->phone)) {
            $score += 15;
        } else {
            $flags[] = 'missing_phone';
        }

        // Company name
        if (! empty($customer->company_name)) {
            $normCompany = $this->normalizeCompany($customer->company_name);
            if (strlen($normCompany) < 3) {
                $flags[] = 'weak_company_name';
            } else {
                $score += 20;
            }
        } else {
            if ($customer->customer_type === 'b2b') {
                $flags[] = 'missing_company';
            }
        }

        // Country
        if (! empty($customer->country)) {
            $score += 15;
        } else {
            $flags[] = 'missing_country';
        }

        // VAT / business tax ID
        if (! empty($customer->vat_number)) {
            $score += 15;
        }

        // Saved delivery address
        $hasAddress = $customer->addresses()->exists();
        if ($hasAddress) {
            $score += 10;
        } else {
            $flags[] = 'missing_address';
        }

        // Segmentation quality (admin has reviewed the customer)
        $segmentSet = ! in_array($customer->customer_segment ?? 'unknown', ['unknown', null], true);
        $accessSet  = ! in_array($customer->access_level ?? 'inquiry_only', ['inquiry_only', null], true);
        if ($segmentSet || $accessSet) {
            $score += 5;
        }

        // Personal email for B2B
        if ($customer->customer_type === 'b2b') {
            $domain = strtolower(strstr($customer->email ?? '', '@'));
            $domain = ltrim($domain, '@');
            if (in_array($domain, self::FREE_EMAIL_DOMAINS, true)) {
                $flags[] = 'personal_email_for_b2b';
                $score  -= 5;
            }
        }

        if ($score < 50) {
            $flags[] = 'incomplete_profile';
        }

        return [$score, $flags];
    }

    /** @return list<string> */
    private function buildDuplicateFlags(array $duplicates): array
    {
        $flags = [];
        if (isset($duplicates['exact_email']))          $flags[] = 'duplicate_email';
        if (isset($duplicates['exact_phone']))          $flags[] = 'duplicate_phone';
        if (isset($duplicates['same_company_country'])) $flags[] = 'duplicate_company_country';
        return $flags;
    }

    private function determineReviewStatus(int $score, array $flags, array $duplicates): string
    {
        if (! empty($duplicates)) {
            return 'duplicate_suspected';
        }
        if ($score < 50 || in_array('incomplete_profile', $flags, true)) {
            return 'needs_review';
        }
        return 'clean';
    }
}
