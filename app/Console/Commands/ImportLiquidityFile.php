<?php

namespace App\Console\Commands;

use App\Models\FinanceLiquidityEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load finance's weekly liquidity file (Session 105).
 *
 * Takes the Details ledger of "Liquidity File V1" as CSV — columns
 * Item, Supplier, Description, Week, Currency, Amount, Comment — and writes
 * one finance_liquidity_entries row per line. Survey by default, writes
 * only with --fix; --replace clears the existing working first, because
 * the file IS finance's current working and merging two generations of it
 * would double every figure.
 *
 * Rejects rather than guesses, the markets:import-reference rule: an item
 * label that maps to no known line, an unreadable week or a non-numeric
 * amount refuses the row and names its line number. A figure filed under
 * the wrong category is worse than one that is absent.
 */
class ImportLiquidityFile extends Command
{
    protected $signature = 'liquidity:import
        {file : CSV with Item,Supplier,Description,Week,Currency,Amount,Comment}
        {--fix : Actually write; without it the command only reports}
        {--replace : Delete every existing liquidity entry first}
        {--year= : ISO week-year for bare week numbers (default: current)}';

    protected $description = "Import finance's weekly liquidity file into the snapshot board";

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $year = (int) ($this->option('year') ?: now()->isoWeekYear);

        // Label → line key, case-insensitive; keys themselves accepted too.
        $lineMap = [];
        foreach (FinanceLiquidityEntry::LINES as $key => $label) {
            $lineMap[mb_strtolower($label)] = $key;
            $lineMap[mb_strtolower($key)]   = $key;
        }

        $rows     = [];
        $rejected = [];
        $handle   = fopen($path, 'r');
        $lineNo   = 0;
        $now      = now();

        while (($cols = fgetcsv($handle)) !== false) {
            $lineNo++;

            // BOM on the first cell (Excel), header row, blank lines.
            if (isset($cols[0])) {
                $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0]);
            }
            $cols = array_map(fn ($c) => trim((string) $c), $cols);
            if ($cols === [''] || $cols[0] === '' || mb_strtolower($cols[0]) === 'item') {
                continue;
            }

            [$item, $supplier, $description, $week, $currency, $amount, $comment] =
                array_pad($cols, 7, '');

            $lineKey = $lineMap[mb_strtolower($item)] ?? null;
            if ($lineKey === null) {
                $rejected[] = "line {$lineNo}: unknown item '{$item}'";
                continue;
            }

            if (! preg_match('/(\d{1,2})/', $week, $m)) {
                $rejected[] = "line {$lineNo}: unreadable week '{$week}'";
                continue;
            }
            $weekKey = sprintf('%d-W%02d', $year, (int) $m[1]);

            $cleanAmount = str_replace([',', ' '], '', $amount);
            if ($cleanAmount === '' || ! is_numeric($cleanAmount)) {
                $rejected[] = "line {$lineNo}: non-numeric amount '{$amount}'";
                continue;
            }

            $cur = strtoupper(substr($currency ?: 'EUR', 0, 3));
            if ($cur === 'EUR' || str_starts_with(mb_strtolower($currency), 'eur')) {
                $cur = 'EUR';
            }

            $rows[] = [
                'line'        => $lineKey,
                'period'      => '',
                'week_key'    => $weekKey,
                'supplier'    => mb_substr($supplier, 0, 150) ?: null,
                'description' => mb_substr($description, 0, 255),
                'reference'   => null,
                'amount'      => (float) $cleanAmount,
                'currency'    => $cur,
                'comment'     => mb_substr($comment, 0, 255) ?: null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }
        fclose($handle);

        // The survey — what would land, per line, per week.
        $byLine = collect($rows)->groupBy('line');
        $this->info(count($rows) . ' row(s) readable, ' . count($rejected) . ' rejected.');
        foreach (FinanceLiquidityEntry::LINES as $key => $label) {
            $group = $byLine->get($key);
            if ($group) {
                $this->line(sprintf('  %-22s %3d row(s)  %12.2f', $label, $group->count(), $group->sum('amount')));
            }
        }
        foreach ($rejected as $r) {
            $this->warn('  REJECTED ' . $r);
        }

        $existing = FinanceLiquidityEntry::count();
        if ($this->option('replace')) {
            $this->line("  --replace: {$existing} existing entr(ies) will be deleted first.");
        } elseif ($existing > 0) {
            $this->warn("  {$existing} existing entr(ies) will be KEPT — pass --replace if this file supersedes them.");
        }

        if ($rejected !== []) {
            $this->error('Fix the rejected rows first — nothing was written.');

            return self::FAILURE;
        }

        if (! $this->option('fix')) {
            $this->info('Survey only. Re-run with --fix to write.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            if ($this->option('replace')) {
                FinanceLiquidityEntry::query()->delete();
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                FinanceLiquidityEntry::insert($chunk);
            }
        });

        $this->info('Imported ' . count($rows) . ' entr(ies).');

        return self::SUCCESS;
    }
}
