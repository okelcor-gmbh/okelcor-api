<?php

namespace App\Services;

use App\Models\MarketingContact;
use Illuminate\Support\Str;

/**
 * Imports a contact CSV into the marketing_contacts list used for admin
 * bulk-email campaigns. Column headers are matched case-insensitively
 * against a set of known aliases per field (not a single fixed header row),
 * because real-world exports vary — Wix's export uses "Email 1", other
 * sources use "Email", "Company name", etc. Unlike WixCustomerImportService
 * this never creates a Customer/login account and never sends a welcome
 * email — it only builds the mailing list.
 */
class MarketingContactImportService
{
    /**
     * The market every Wix-exported contact joins, on top of whichever market
     * the operator selected for the upload.
     *
     * The marketing team want to be able to mail "everyone who came across
     * from Wix" as an audience in its own right. That is not the same question
     * as "everyone in Germany" — it is a question about where a contact came
     * from — so it is a market they hold IN ADDITION to their geographic one,
     * never instead of it. Contacts are many-to-many with markets precisely so
     * this kind of overlapping audience is possible (Session 72).
     */
    public const WIX_MARKET = 'wix';

    public const SOURCE_WIX = 'wix';

    /**
     * Headers only a Wix contact export has.
     *
     * Wix numbers its repeated fields — `Email 1`, `Phone 1`, `Address 1 -
     * Country` — and nothing else the marketing team uploads does. Detecting
     * the format from its own headers rather than asking the operator to tick
     * a box means the tagging cannot be forgotten on an upload, which is the
     * only way a "send to all Wix contacts" audience stays true over time.
     *
     * Three of these must be present, so one column named `Email 1` in an
     * unrelated spreadsheet does not tag a whole list as Wix.
     */
    private const WIX_HEADER_SIGNATURE = [
        'email 1',
        'phone 1',
        'address 1 - country',
        'address 1 - street',
        'address 1 - city',
        'address 2 - country',
    ];

    private const WIX_SIGNATURE_MIN_MATCHES = 3;

    private const STATUS_MAP = [
        'subscribed'       => 'subscribed',
        'unsubscribed'     => 'unsubscribed',
        'never subscribed' => 'unknown',
    ];

    /**
     * Logical field => accepted header names (lowercase, trimmed).
     * First match wins. Add new aliases here when a new export format
     * shows up — never hardcode a single header string in the parse loop.
     */
    private const FIELD_ALIASES = [
        'email'      => ['email 1', 'email', 'email address', 'e-mail', 'e-mail address'],
        'first_name' => ['first name', 'firstname', 'first_name'],
        'last_name'  => ['last name', 'lastname', 'last_name'],
        'phone'      => ['phone 1', 'phone', 'phone number', 'mobile', 'tel'],
        'company'    => ['company', 'company name', 'business name', 'organization', 'organisation'],
        'country'    => ['address 1 - country', 'country'],
        'market'     => ['market', 'region', 'segment'],
        'vat_id'     => ['vat id', 'vat', 'vat number'],
        'labels'     => ['labels', 'business type', 'bussines type', 'type', 'category'],
        'source'     => ['source'],
        'status'     => ['email subscriber status', 'subscriber status', 'status'],
    ];

    /**
     * $defaultMarket applies to every row unless that row's own CSV has a
     * market/region/segment column with a value — lets a marketing team
     * member upload a whole batch under one market selector without
     * needing a market column in the file at all, while still supporting
     * a pre-tagged export if one exists.
     */
    public function import(string $filePath, ?string $defaultMarket = null): array
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        $rawHeaders    = fgetcsv($handle);
        $rawHeaders[0] = ltrim($rawHeaders[0] ?? '', "\xEF\xBB\xBF");
        $headers       = array_map(fn ($h) => strtolower(trim($h)), $rawHeaders);

        if (! in_array(true, array_map(fn ($h) => in_array($h, self::FIELD_ALIASES['email'], true), $headers), true)) {
            fclose($handle);
            throw new \RuntimeException(
                'No email column found. Expected one of: ' . implode(', ', self::FIELD_ALIASES['email']) . '.'
            );
        }

        $isWixExport = $this->looksLikeWixExport($headers);

        // The operator's chosen market, plus `wix` when the file is a Wix
        // export. Order matters: the chosen market is applied first, so it
        // stays the contact's PRIMARY market and nothing appears to have been
        // relocated into `wix`.
        // array_unique matters: importing a Wix export UNDER the `wix` market —
        // which is the sensible choice for a list whose only known
        // segmentation is where it came from — would otherwise report
        // ["wix","wix"]. Frontend keys its "nothing was moved" explainer on
        // this being longer than one entry, so a duplicate makes it say the
        // contacts were added to a market they were already imported into.
        $markets = array_values(array_unique(array_filter([
            $defaultMarket,
            $isWixExport ? self::WIX_MARKET : null,
        ])));

        $stats = [
            'imported'         => 0,
            'updated'          => 0,
            'skipped_no_email' => 0,
            'unsubscribed'     => 0,
            'subscribed'       => 0,
            'errors'           => [],
            // Reported back so the UI can say "1,720 contacts imported and
            // also added to the wix market" rather than the operator having to
            // notice it themselves.
            'source_detected'  => $isWixExport ? self::SOURCE_WIX : null,
            'markets_applied'  => $markets,
        ];

        $row = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row++;

            if (count($data) !== count($headers)) {
                $data = array_pad($data, count($headers), '');
            }

            $record = array_combine($headers, $data);
            $record = array_map('trim', $record);

            $email = strtolower($this->field($record, 'email') ?? '');

            if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['skipped_no_email']++;
                continue;
            }

            try {
                $status   = $this->mapStatus($this->field($record, 'status') ?? '');
                $existing = MarketingContact::where('email', $email)->first();

                // Never let a re-import silently re-subscribe someone who opted out.
                if ($existing && $existing->status === 'unsubscribed') {
                    $status = 'unsubscribed';
                }

                // A row carrying its own market wins over the upload-wide
                // selection, exactly as before — `wix` is then added on top of
                // whichever of the two applied.
                $rowMarket  = $this->field($record, 'market') ?: $defaultMarket;
                $rowMarkets = array_values(array_unique(array_filter([
                    $rowMarket,
                    $isWixExport ? self::WIX_MARKET : null,
                ])));

                $attributes = [
                    'first_name'  => $this->field($record, 'first_name'),
                    'last_name'   => $this->field($record, 'last_name'),
                    'phone'       => $this->cleanPhone($this->field($record, 'phone') ?? ''),
                    'company'     => $this->field($record, 'company'),
                    'country'     => $this->field($record, 'country'),
                    'vat_id'      => $this->field($record, 'vat_id'),
                    'labels'      => $this->field($record, 'labels'),
                    // A `source` column in the file still wins; the stamp only
                    // fills the gap Wix leaves, since its export has no such
                    // column and every row imported before this landed
                    // recorded a null source.
                    'source'      => $this->field($record, 'source') ?: ($isWixExport ? self::SOURCE_WIX : null),
                    'status'      => $status,
                    'imported_at' => now(),
                ];

                if ($existing) {
                    // Deliberately does NOT set `market`: importing a Germany
                    // list that happens to contain an existing Asia contact
                    // ADDS germany alongside asia rather than moving them out
                    // of it. Before markets were many-to-many this overwrote
                    // the column, silently relocating contacts as a side effect
                    // of an unrelated import. Use the move-market endpoint when
                    // a relocation is actually what's wanted.
                    $existing->update($attributes);
                    if ($rowMarkets !== []) {
                        $existing->addMarkets($rowMarkets);
                    }
                    $stats['updated']++;
                } else {
                    $attributes['email']             = $email;
                    $attributes['market']            = $rowMarket;
                    $attributes['unsubscribe_token']  = $this->generateToken();
                    $contact = MarketingContact::create($attributes);
                    if ($rowMarkets !== []) {
                        $contact->addMarkets($rowMarkets);
                    }
                    $stats['imported']++;
                }

                if ($status === 'unsubscribed') {
                    $stats['unsubscribed']++;
                } elseif ($status === 'subscribed') {
                    $stats['subscribed']++;
                }

            } catch (\Throwable $e) {
                $stats['errors'][] = "Row {$row} ({$email}): " . $e->getMessage();
            }
        }

        fclose($handle);

        return $stats;
    }

    /**
     * Whether these headers are Wix's own contact export.
     *
     * @param  array<int, string>  $headers  already lowercased and trimmed
     */
    public function looksLikeWixExport(array $headers): bool
    {
        $matches = count(array_intersect(self::WIX_HEADER_SIGNATURE, $headers));

        return $matches >= self::WIX_SIGNATURE_MIN_MATCHES;
    }

    /**
     * Look up a logical field in an already-lowercased-header record via
     * FIELD_ALIASES. Returns null for missing/blank so callers can `?:` it.
     */
    private function field(array $record, string $field): ?string
    {
        foreach (self::FIELD_ALIASES[$field] as $alias) {
            if (array_key_exists($alias, $record) && $record[$alias] !== '') {
                return $record[$alias];
            }
        }

        return null;
    }

    private function mapStatus(string $raw): string
    {
        return self::STATUS_MAP[strtolower(trim($raw))] ?? 'unknown';
    }

    private function cleanPhone(string $phone): ?string
    {
        $phone = trim($phone, " \t\n\r\0\x0B'\"");

        return empty($phone) ? null : $phone;
    }

    private function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (MarketingContact::where('unsubscribe_token', $token)->exists());

        return $token;
    }
}
