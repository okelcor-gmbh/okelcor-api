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
        'vat_id'     => ['vat id', 'vat', 'vat number'],
        'labels'     => ['labels', 'business type', 'bussines type', 'type', 'category'],
        'source'     => ['source'],
        'status'     => ['email subscriber status', 'subscriber status', 'status'],
    ];

    public function import(string $filePath): array
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

        $stats = [
            'imported'         => 0,
            'updated'          => 0,
            'skipped_no_email' => 0,
            'unsubscribed'     => 0,
            'subscribed'       => 0,
            'errors'           => [],
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

                $attributes = [
                    'first_name'  => $this->field($record, 'first_name'),
                    'last_name'   => $this->field($record, 'last_name'),
                    'phone'       => $this->cleanPhone($this->field($record, 'phone') ?? ''),
                    'company'     => $this->field($record, 'company'),
                    'country'     => $this->field($record, 'country'),
                    'vat_id'      => $this->field($record, 'vat_id'),
                    'labels'      => $this->field($record, 'labels'),
                    'source'      => $this->field($record, 'source'),
                    'status'      => $status,
                    'imported_at' => now(),
                ];

                if ($existing) {
                    $existing->update($attributes);
                    $stats['updated']++;
                } else {
                    $attributes['email']             = $email;
                    $attributes['unsubscribe_token']  = $this->generateToken();
                    MarketingContact::create($attributes);
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
