<?php

namespace App\Services;

use App\Models\MarketingContact;
use Illuminate\Support\Str;

/**
 * Imports a Wix-style contact export (same column layout as
 * WixCustomerImportService) into the marketing_contacts list used for admin
 * bulk-email campaigns. Unlike WixCustomerImportService this never creates a
 * Customer/login account and never sends a welcome email — it only builds
 * the mailing list.
 */
class MarketingContactImportService
{
    private const STATUS_MAP = [
        'subscribed'       => 'subscribed',
        'unsubscribed'     => 'unsubscribed',
        'never subscribed' => 'unknown',
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
        $rawHeaders[0] = ltrim($rawHeaders[0], "\xEF\xBB\xBF");
        $headers       = array_map('trim', $rawHeaders);

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

            $email = strtolower($record['Email 1'] ?? '');

            if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['skipped_no_email']++;
                continue;
            }

            try {
                $status  = $this->mapStatus($record['Email subscriber status'] ?? '');
                $existing = MarketingContact::where('email', $email)->first();

                // Never let a re-import silently re-subscribe someone who opted out.
                if ($existing && $existing->status === 'unsubscribed') {
                    $status = 'unsubscribed';
                }

                $attributes = [
                    'first_name' => $record['First Name'] ?? null ?: null,
                    'last_name'  => $record['Last Name'] ?? null ?: null,
                    'phone'      => $this->cleanPhone($record['Phone 1'] ?? ''),
                    'company'    => $record['Company'] ?? null ?: null,
                    'country'    => $record['Address 1 - Country'] ?? null ?: null,
                    'vat_id'     => $record['VAT ID'] ?? null ?: null,
                    'labels'     => $record['Labels'] ?? null ?: null,
                    'source'     => $record['Source'] ?? null ?: null,
                    'status'     => $status,
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
