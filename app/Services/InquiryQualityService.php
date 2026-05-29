<?php

namespace App\Services;

/**
 * Scores a quote/inquiry submission for quality and spam.
 *
 * Design principles:
 * - Generous: a terse but genuine message must reach at least needs_review
 * - Non-destructive: never silently drops anything; spam is stored + flagged
 * - Safe for non-native English: no language-quality checks
 * - Two layers: hard spam flags (always → spam) then positive/negative signals (score → band)
 */
class InquiryQualityService
{
    // ── Known bad domains ────────────────────────────────────────────────────

    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamailblock.com',
        'temp-mail.org', 'throwam.com', 'yopmail.com', 'trashmail.com',
        'fakeinbox.com', 'dispostable.com', 'sharklasers.com', 'spam4.me',
        '10minutemail.com', 'minuteinbox.com', 'mailnull.com', 'spamgourmet.com',
        'mytrashmail.com', 'throwaway.email', 'tempail.com', 'maildrop.cc',
        'tempinbox.com', 'mailtemp.net', 'trashmail.me', 'getairmail.com',
        'filzmail.com', 'spamfree24.org', 'binkmail.com', 'spamavert.com',
    ];

    private const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com',
        'icloud.com', 'aol.com', 'mail.com', 'protonmail.com', 'zoho.com',
        'gmx.com', 'gmx.net', 'web.de', 'yahoo.co.uk', 'yahoo.de', 'yahoo.fr',
    ];

    // Keyboard sequences that indicate garbage input
    private const KEYBOARD_SMASH_PATTERNS = [
        'asdf', 'qwert', 'zxcv', 'hjkl', 'uiop', 'bnm,',
        'abcde', 'abcd', 'wxyz', 'qwertyuiop', 'asdfghjkl',
    ];

    private const BUYING_INTENT_WORDS = [
        'need', 'require', 'quote', 'price', 'order', 'buy', 'purchase',
        'inquir', 'looking for', 'want', 'request', 'supply', 'deliver',
        'wholesale', 'export', 'import', 'interested', 'seeking',
    ];

    private const TYRE_KEYWORDS = [
        'tyre', 'tire', 'tyres', 'tires', 'pcr', 'tbr', 'otr',
        'summer', 'winter', 'all-season', 'all season', 'radial', 'tubeless',
        'passenger', 'truck', 'suv', 'fleet', 'bus', 'van', 'trailer',
        'michelin', 'bridgestone', 'goodyear', 'continental', 'pirelli',
        'dunlop', 'hankook', 'yokohama', 'nokian', 'falken', 'toyo',
        'nexen', 'kumho', 'cooper', 'bf goodrich', 'rapid', 'linglong',
    ];

    // Common destination countries/cities for Okelcor B2B exports
    private const DESTINATIONS = [
        'ghana', 'nigeria', 'kenya', 'uganda', 'tanzania', 'ethiopia',
        'mozambique', 'zambia', 'zimbabwe', 'angola', 'cameroon', 'senegal',
        'ivory coast', 'cote d\'ivoire', 'mali', 'niger', 'burkina faso',
        'benin', 'togo', 'congo', 'drc', 'rwanda', 'sudan', 'south africa',
        'egypt', 'morocco', 'algeria', 'tunisia', 'libya', 'somalia',
        'ghana', 'sierra leone', 'liberia', 'guinea', 'gambia', 'gabon',
        'germany', 'france', 'uk', 'poland', 'netherlands', 'belgium',
        'spain', 'italy', 'portugal', 'romania', 'turkey', 'ukraine',
        'india', 'china', 'uae', 'dubai', 'saudi arabia', 'iraq',
        'jordan', 'kuwait', 'pakistan', 'bangladesh', 'sri lanka',
        'brazil', 'colombia', 'peru', 'chile', 'mexico', 'usa',
        'africa', 'europe', 'asia', 'middle east',
        // Major cities as destination signals
        'lagos', 'accra', 'nairobi', 'abuja', 'kampala', 'addis ababa',
        'dar es salaam', 'johannesburg', 'cape town', 'casablanca',
        'dubai', 'istanbul', 'karachi', 'dhaka', 'mumbai', 'delhi',
    ];

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Score the validated quote request payload.
     *
     * @param  array<string, mixed>  $validated  Validated form data
     * @return array{ review_status: string, quality_score: int, quality_flags: list<string> }
     */
    public function score(array $validated): array
    {
        $notes       = trim($validated['notes'] ?? '');
        $notesLower  = strtolower($notes);
        $email       = strtolower($validated['email'] ?? '');
        $emailDomain = strstr($email, '@') ? ltrim(strstr($email, '@'), '@') : '';

        // ── Hard spam detection ──────────────────────────────────────────────
        $hardFlags = $this->detectHardFlags($notesLower, $notes, $emailDomain);

        if (! empty($hardFlags)) {
            return [
                'review_status' => 'spam',
                'quality_score' => 0,
                'quality_flags' => $hardFlags,
            ];
        }

        // ── Positive/negative signals ────────────────────────────────────────
        [$score, $softFlags] = $this->computeScore($notesLower, $emailDomain, $validated);

        $reviewStatus = match (true) {
            $score >= 60 => 'qualified',
            default      => 'needs_review',
        };

        return [
            'review_status' => $reviewStatus,
            'quality_score' => $score,
            'quality_flags' => $softFlags,
        ];
    }

    // ── Hard spam detection ──────────────────────────────────────────────────

    /** @return list<string> */
    private function detectHardFlags(string $notesLower, string $notesRaw, string $emailDomain): array
    {
        $flags = [];

        // 1. Too short
        if (mb_strlen(trim($notesLower)) < 8) {
            $flags[] = 'message_too_short';
        }

        // 2. Repeated single character (> 70 % of non-whitespace content)
        $noSpaces = preg_replace('/\s+/', '', $notesLower);
        if (strlen($noSpaces) >= 4) {
            $charFreq = array_count_values(str_split($noSpaces));
            $maxFreq  = max($charFreq);
            if ($maxFreq / strlen($noSpaces) > 0.70) {
                $flags[] = 'repeated_chars';
            }
        }

        // 3. Keyboard smash — only flag when notes is short (< 25 chars) or dominated
        if (! in_array('message_too_short', $flags, true)) {
            foreach (self::KEYBOARD_SMASH_PATTERNS as $pattern) {
                if (str_contains($notesLower, $pattern)) {
                    similar_text($notesLower, $pattern, $pct);
                    if ($pct > 55 || mb_strlen($notesLower) < 20) {
                        $flags[] = 'keyboard_smash';
                        break;
                    }
                }
            }
        }

        // 4. Only digits / symbols
        if (! empty($notesLower) && preg_match('/^[\d\s\.\!\?\-\+\*\#\@\$\%\^\&\(\)\,\/]+$/', $notesLower)) {
            $flags[] = 'only_numbers_symbols';
        }

        // 5. URL spam (2 or more URLs)
        $urlCount = preg_match_all('/https?:\/\/\S+|www\.\S+/i', $notesRaw, $urlMatches);
        if ($urlCount >= 2) {
            $flags[] = 'url_spam';
        }

        // 6. Disposable email domain
        if ($emailDomain && in_array($emailDomain, self::DISPOSABLE_DOMAINS, true)) {
            $flags[] = 'disposable_email';
        }

        return $flags;
    }

    // ── Signal scoring ───────────────────────────────────────────────────────

    /** @return array{int, list<string>} */
    private function computeScore(string $notesLower, string $emailDomain, array $validated): array
    {
        $score = 0;
        $flags = [];

        $tyreSize      = strtolower($validated['tyre_size'] ?? '');
        $tyreItems     = $validated['tyre_items'] ?? null;
        $quantity      = $validated['quantity'] ?? '';
        $companyName   = trim($validated['company_name'] ?? '');
        $phone         = trim($validated['phone'] ?? '');
        $countryField  = trim($validated['country'] ?? '');
        $brandPref     = trim($validated['brand_preference'] ?? '');
        $vatNumber     = trim($validated['vat_number'] ?? '');

        // ── Tyre details (most important signal) ────────────────────────────
        $hasTyreSizePattern = $this->containsTyreSizePattern($notesLower) ||
                              $this->containsTyreSizePattern($tyreSize) ||
                              $this->tyreItemsHaveSize($tyreItems);

        $hasTyreKeyword = $this->containsAny($notesLower, self::TYRE_KEYWORDS);

        if ($hasTyreSizePattern) {
            $score += 20;   // explicit size → strongest tyre signal
        } elseif ($hasTyreKeyword) {
            $score += 10;   // mentions tyres but no specific size
            $flags[] = 'no_specific_size';
        } else {
            $score -= 15;
            $flags[] = 'no_tyre_details';
        }

        // ── Quantity ─────────────────────────────────────────────────────────
        if ($this->containsQuantity($notesLower) || $this->isValidQuantity($quantity)) {
            $score += 15;
        } else {
            $flags[] = 'no_quantity';
        }

        // ── Destination / country ────────────────────────────────────────────
        if ($countryField !== '') {
            $score += 15;
        } elseif ($this->containsAny($notesLower, self::DESTINATIONS)) {
            $score += 12;
        } else {
            $score -= 10;
            $flags[] = 'no_destination';
        }

        // ── Buying intent ─────────────────────────────────────────────────────
        if ($this->containsAny($notesLower, self::BUYING_INTENT_WORDS)) {
            $score += 10;
        }

        // ── Brand (field or notes) ───────────────────────────────────────────
        if ($brandPref !== '' || $this->containsAny($notesLower, array_slice(self::TYRE_KEYWORDS, 8))) {
            $score += 10;
        }

        // ── Contact richness ──────────────────────────────────────────────────
        if ($companyName !== '') {
            $score += 10;
        } else {
            $flags[] = 'missing_company_name';
        }

        if ($phone !== '') {
            $score += 10;
        } else {
            $flags[] = 'missing_phone';
        }

        // ── Message effort ────────────────────────────────────────────────────
        if (mb_strlen(trim($notesLower)) > 60) {
            $score += 5;
        }

        // ── Email domain signals ──────────────────────────────────────────────
        if ($emailDomain) {
            if (in_array($emailDomain, self::FREE_EMAIL_DOMAINS, true)) {
                $score -= 5;
                $flags[] = 'free_email_domain';
            } elseif (! in_array($emailDomain, self::DISPOSABLE_DOMAINS, true)) {
                $score += 5;  // business domain
            }
        }

        // ── VAT / business verification bonus ────────────────────────────────
        if ($vatNumber !== '') {
            $score += 5;
        }

        return [max(0, min(100, $score)), $flags];
    }

    // ── Pattern helpers ──────────────────────────────────────────────────────

    private function containsTyreSizePattern(string $text): bool
    {
        // Matches: 205/55R16, 315/80R22.5, 295 80R22.5, 205-55R16, etc.
        return (bool) preg_match('/\d{3}\s*[\/\-]\s*\d{2,3}\s*[Rr]\s*\d{2}(\.\d)?/', $text);
    }

    private function tyreItemsHaveSize(?array $items): bool
    {
        if (empty($items)) {
            return false;
        }
        foreach ($items as $item) {
            if (! empty($item['size']) && strlen($item['size']) > 3) {
                return true;
            }
        }
        return false;
    }

    private function containsQuantity(string $text): bool
    {
        // Looks for standalone numbers (likely quantities): "200 tyres", "500 pcs", "120 units"
        return (bool) preg_match('/\b\d{1,5}\b/', $text);
    }

    private function isValidQuantity(string $quantity): bool
    {
        $n = filter_var(preg_replace('/[^\d]/', '', $quantity), FILTER_VALIDATE_INT);
        return $n !== false && $n > 0;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
