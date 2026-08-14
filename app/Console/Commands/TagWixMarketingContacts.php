<?php

namespace App\Console\Commands;

use App\Models\MarketingContact;
use App\Services\MarketingContactImportService;
use Illuminate\Console\Command;

/**
 * Adds the `wix` market to contacts that came across from Wix.
 *
 * The import now tags Wix exports on sight, but the ~1,720 contacts loaded in
 * Session 50 predate that and recorded nothing about where they came from —
 * `source` was read from a CSV column Wix does not provide, so it is null on
 * every one of them. Nothing in the database can distinguish them from a
 * contact typed in by hand.
 *
 * So this does not guess. It takes an explicit selector and, for the case that
 * actually matters, the original export file: `--file=contacts.csv` matches on
 * e-mail, which is the only definition of "came from Wix" that is true rather
 * than inferred.
 *
 * Dry-run by default. Adding a market is reversible, but a bulk write against
 * the wrong selector is still a mess to unpick, and the count printed by a
 * survey is the thing that tells you the selector was right.
 */
class TagWixMarketingContacts extends Command
{
    protected $signature = 'marketing:tag-wix
        {--file= : Path to the original Wix export CSV; contacts are matched by e-mail}
        {--source=* : Tag contacts whose source column is one of these (e.g. wix)}
        {--market=* : Tag every contact currently in these markets}
        {--all : Tag every marketing contact — use only if the whole list came from Wix}
        {--stamp-source : Also set source=wix on the contacts that are tagged}
        {--fix : Actually write. Without this the command only reports}';

    protected $description = 'Add the "wix" market to marketing contacts that came from the Wix export';

    public function handle(): int
    {
        if (! MarketingContact::supportsMultipleMarkets()) {
            $this->error('The marketing_contact_markets table does not exist — run migrations first.');
            $this->line('Without it a contact can hold only one market, and tagging would MOVE contacts out of the market they are in.');

            return self::FAILURE;
        }

        $selectors = array_filter([
            'file'   => $this->option('file'),
            'source' => $this->option('source') ?: null,
            'market' => $this->option('market') ?: null,
            'all'    => $this->option('all') ?: null,
        ]);

        if ($selectors === []) {
            $this->error('Pick what to tag: --file=, --source=, --market= or --all.');
            $this->line('Nothing in the database records which contacts came from Wix, so this command will not guess.');

            return self::FAILURE;
        }

        $emails = null;

        if ($file = $this->option('file')) {
            $emails = $this->emailsFrom($file);

            if ($emails === null) {
                return self::FAILURE;
            }

            $this->line('Read ' . count($emails) . ' e-mail address(es) from ' . $file . '.');
        }

        $query = MarketingContact::query();

        if ($emails !== null) {
            // Chunked: `whereIn` with a few thousand bound parameters is a
            // query some MySQL configurations refuse outright.
            $query->where(function ($q) use ($emails) {
                foreach (array_chunk($emails, 500) as $chunk) {
                    $q->orWhereIn('email', $chunk);
                }
            });
        } elseif ($sources = $this->option('source')) {
            $query->whereIn('source', $sources);
        } elseif ($markets = $this->option('market')) {
            $query->whereHas('marketMemberships', fn ($q) => $q->whereIn('market', $markets));
        }
        // --all adds no constraint, which is the whole of what it means.

        $total    = (clone $query)->count();
        $tagged   = 0;
        $already  = 0;
        $writing  = (bool) $this->option('fix');

        if ($total === 0) {
            $this->warn('That selector matched no contacts. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line(($writing ? 'Tagging ' : 'Would tag ') . $total . ' contact(s) with the "'
            . MarketingContactImportService::WIX_MARKET . '" market.');

        $query->orderBy('id')->chunkById(200, function ($contacts) use (&$tagged, &$already, $writing) {
            foreach ($contacts as $contact) {
                if (in_array(MarketingContactImportService::WIX_MARKET, $contact->marketNames(), true)) {
                    $already++;
                    continue;
                }

                $tagged++;

                if (! $writing) {
                    continue;
                }

                // addMarkets ADDS — the contact keeps every market it already
                // held, and its primary market is untouched. A contact in
                // `croatia` ends up in croatia AND wix, which is the point.
                $contact->addMarkets([MarketingContactImportService::WIX_MARKET]);

                if ($this->option('stamp-source') && ! $contact->source) {
                    $contact->update(['source' => MarketingContactImportService::SOURCE_WIX]);
                }
            }
        });

        $this->newLine();
        $this->line("Matched:            {$total}");
        $this->line("Already in wix:     {$already}");
        $this->line(($writing ? 'Tagged:             ' : 'Would tag:          ') . $tagged);

        if (! $writing) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run with --fix once the numbers above look right.');
        }

        return self::SUCCESS;
    }

    /**
     * The e-mail column out of a Wix export, using the importer's own header
     * aliases so the two cannot disagree about which column that is.
     *
     * @return array<int, string>|null  null on an unreadable file
     */
    private function emailsFrom(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return null;
        }

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

        if (! app(MarketingContactImportService::class)->looksLikeWixExport($headers)) {
            $this->warn('Those headers do not look like a Wix export. Continuing, because the file you name is '
                . 'the definition of what came from Wix — but check it is the right one.');
        }

        $emails = [];

        while (($row = fgetcsv($handle)) !== false) {
            $email = strtolower(trim((string) ($row[$column] ?? '')));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = true;
            }
        }

        fclose($handle);

        return array_keys($emails);
    }
}
