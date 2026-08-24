<?php

namespace App\Console\Commands;

use App\Models\MarketReferenceStat;
use App\Support\CountryNormaliser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Load outside market data — trade statistics, fleet size, whatever can be
 * obtained — so markets Okelcor has no traffic from stop being invisible.
 *
 * Survey by default, write only with --fix. Same shape as the other data
 * commands in this project, and for the same reason: a command that writes
 * the moment you run it gets run by accident exactly once.
 */
class ImportMarketReferenceStats extends Command
{
    protected $signature = 'markets:import-reference
                            {file            : CSV path. Columns: country,metric,value[,unit,period,source,notes]}
                            {--fix           : Actually write. Without it this only reports what would happen.}
                            {--source=       : Attribution applied to rows that do not carry their own.}';

    protected $description = 'Import external per-country market statistics used by the market intelligence report.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        if (! Schema::hasTable('market_reference_stats')) {
            $this->error('The market_reference_stats table does not exist yet — run `artisan migrate` first.');

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('The file is empty.');
            fclose($handle);

            return self::FAILURE;
        }

        // Strip a UTF-8 BOM off the first header cell — Excel writes one, and
        // it silently turns "country" into a column nobody can find.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $columns   = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        foreach (['country', 'metric', 'value'] as $required) {
            if (! in_array($required, $columns, true)) {
                $this->error("Missing required column '{$required}'. Found: " . implode(', ', $columns));
                fclose($handle);

                return self::FAILURE;
            }
        }

        $accepted = [];
        $rejected = [];
        $line     = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($row === [null] || $row === []) {
                continue;
            }

            $data    = array_combine($columns, array_pad(array_slice($row, 0, count($columns)), count($columns), null));
            $rawName = trim((string) ($data['country'] ?? ''));
            $code    = CountryNormaliser::normalise($rawName);

            if ($code === null) {
                // Rejected, not guessed. A statistic filed under the wrong
                // country is worse than one that is absent, because it looks
                // like evidence.
                $rejected[] = ['line' => $line, 'country' => $rawName, 'why' => 'country not recognised'];
                continue;
            }

            $value = str_replace([',', ' '], '', (string) ($data['value'] ?? ''));

            if (! is_numeric($value)) {
                $rejected[] = ['line' => $line, 'country' => $rawName, 'why' => "value '{$data['value']}' is not a number"];
                continue;
            }

            $metric = trim((string) ($data['metric'] ?? ''));

            if ($metric === '') {
                $rejected[] = ['line' => $line, 'country' => $rawName, 'why' => 'metric is blank'];
                continue;
            }

            $accepted[] = [
                'country_code' => $code,
                'country'      => CountryNormaliser::name($code),
                'metric'       => $metric,
                'value'        => (float) $value,
                'unit'         => $this->clean($data['unit'] ?? null),
                'period'       => $this->clean($data['period'] ?? null),
                'source'       => $this->clean($data['source'] ?? null) ?: ($this->option('source') ?: null),
                'notes'        => $this->clean($data['notes'] ?? null),
            ];
        }

        fclose($handle);

        $this->newLine();
        $this->info(count($accepted) . ' row(s) ready, ' . count($rejected) . ' rejected.');

        if ($accepted !== []) {
            $this->table(
                ['Country', 'Metric', 'Value', 'Unit', 'Period', 'Source'],
                collect($accepted)->take(20)->map(fn ($r) => [
                    $r['country'] . " ({$r['country_code']})",
                    $r['metric'],
                    rtrim(rtrim(number_format($r['value'], 4, '.', ''), '0'), '.'),
                    $r['unit'] ?? '—',
                    $r['period'] ?? '—',
                    $r['source'] ?? '—',
                ])->all()
            );

            if (count($accepted) > 20) {
                $this->line('  … and ' . (count($accepted) - 20) . ' more.');
            }
        }

        if ($rejected !== []) {
            $this->newLine();
            $this->warn('Rejected rows — nothing was guessed:');
            $this->table(
                ['Line', 'Country as written', 'Reason'],
                collect($rejected)->take(30)->map(fn ($r) => [$r['line'], $r['country'] ?: '(blank)', $r['why']])->all()
            );
            $this->line('  A country not recognised can be added to CountryNormaliser::ALIASES.');
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->comment('Survey only. Re-run with --fix to write these rows.');

            return self::SUCCESS;
        }

        if ($accepted === []) {
            $this->warn('Nothing to write.');

            return self::SUCCESS;
        }

        $written = 0;

        DB::transaction(function () use ($accepted, &$written) {
            foreach ($accepted as $row) {
                MarketReferenceStat::updateOrCreate(
                    [
                        'country_code' => $row['country_code'],
                        'metric'       => $row['metric'],
                        'period'       => $row['period'],
                    ],
                    [
                        'value'  => $row['value'],
                        'unit'   => $row['unit'],
                        'source' => $row['source'],
                        'notes'  => $row['notes'],
                    ],
                );
                $written++;
            }
        });

        $this->newLine();
        $this->info("{$written} row(s) written. They appear in the market report immediately.");

        return self::SUCCESS;
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
