<?php

namespace App\Console\Commands;

use App\Models\MarketingContact;
use App\Services\MarketingContactImportService;
use Illuminate\Console\Command;

/**
 * Imports a marketing contact CSV from the command line.
 *
 * The same service the admin upload screen uses, reached without a browser.
 * Not a convenience: the original Wix export is ~1,950 rows, and each row costs
 * a lookup, a write and a membership write. On shared hosting that runs long
 * enough to hit a web-server timeout while PHP keeps going — which returns a
 * 504 to the operator over a list that is still half-importing, and leaves
 * nobody able to say how far it got.
 *
 * Reports by default. An import that has already happened cannot be undone
 * except by hand, so the count of what is new against what is already there is
 * worth seeing before the write, not after.
 */
class ImportMarketingContacts extends Command
{
    protected $signature = 'marketing:import
        {file : Path to the CSV}
        {--market= : Market to import into (required)}
        {--fix : Actually import. Without this the command only reports}';

    protected $description = 'Import a marketing contact CSV (the admin upload screen, without the browser)';

    public function handle(MarketingContactImportService $service): int
    {
        $path   = $this->argument('file');
        $market = trim((string) $this->option('market'));

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        if ($market === '') {
            $this->error('--market is required. Every contact must land in one.');

            return self::FAILURE;
        }

        // Same normalisation the HTTP endpoint applies, so a CLI import and an
        // upload of the same file cannot produce two near-duplicate markets.
        $market = \Illuminate\Support\Str::slug($market);

        $preview = $this->preview($path, $service);

        if ($preview === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("File:               {$path}");
        $this->line('Recognised as:      ' . ($preview['is_wix'] ? 'a Wix export' : 'a generic contact list'));
        $this->line("Market:             {$market}");

        $markets = array_values(array_unique(array_filter([
            $market,
            $preview['is_wix'] ? MarketingContactImportService::WIX_MARKET : null,
        ])));

        $this->line('Markets applied:    ' . implode(', ', $markets));
        $this->newLine();
        $this->line("Rows with a valid e-mail:   {$preview['valid']}");
        $this->line("Already in the database:    {$preview['existing']}");
        $this->line("New contacts:               {$preview['new']}");

        if ($preview['invalid'] > 0) {
            $this->line("Rows skipped (no e-mail):   {$preview['invalid']}");
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run with --fix once the numbers above look right.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Importing…');

        set_time_limit(0);

        $stats = $service->import($path, $market);

        $this->newLine();
        $this->line("Imported (new):     {$stats['imported']}");
        $this->line("Updated (existing): {$stats['updated']}");
        $this->line("Skipped (no email): {$stats['skipped_no_email']}");
        $this->line("Subscribed:         {$stats['subscribed']}");
        $this->line("Unsubscribed:       {$stats['unsubscribed']}");
        $this->line('Markets applied:    ' . implode(', ', $stats['markets_applied']));

        foreach (array_slice($stats['errors'], 0, 10) as $error) {
            $this->warn($error);
        }

        if (count($stats['errors']) > 10) {
            $this->warn('… and ' . (count($stats['errors']) - 10) . ' more row error(s).');
        }

        $this->newLine();
        $this->info('Done. Contacts already present kept the market they were in and gained the ones above.');

        return self::SUCCESS;
    }

    /**
     * Counts what the import would do, without writing.
     *
     * @return array{valid: int, invalid: int, existing: int, new: int, is_wix: bool}|null
     */
    private function preview(string $path, MarketingContactImportService $service): ?array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->error("Cannot open file: {$path}");

            return null;
        }

        $rawHeaders    = fgetcsv($handle) ?: [];
        $rawHeaders[0] = ltrim($rawHeaders[0] ?? '', "\xEF\xBB\xBF");
        $headers       = array_map(fn ($h) => strtolower(trim((string) $h)), $rawHeaders);

        $column = null;

        foreach (['email 1', 'email', 'email address', 'e-mail', 'e-mail address'] as $alias) {
            $index = array_search($alias, $headers, true);

            if ($index !== false) {
                $column = $index;
                break;
            }
        }

        if ($column === null) {
            fclose($handle);
            $this->error('No e-mail column found in that file.');

            return null;
        }

        $emails  = [];
        $invalid = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $email = strtolower(trim((string) ($row[$column] ?? '')));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;
                continue;
            }

            $emails[$email] = true;
        }

        fclose($handle);

        $emails   = array_keys($emails);
        $existing = 0;

        // Chunked: a `whereIn` carrying a few thousand bound parameters is a
        // query some MySQL configurations refuse outright.
        foreach (array_chunk($emails, 500) as $chunk) {
            $existing += MarketingContact::whereIn('email', $chunk)->count();
        }

        return [
            'valid'    => count($emails),
            'invalid'  => $invalid,
            'existing' => $existing,
            'new'      => count($emails) - $existing,
            'is_wix'   => $service->looksLikeWixExport($headers),
        ];
    }
}
