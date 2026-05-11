<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SyncRapidProducts extends Command
{
    protected $signature = 'products:sync-rapid
                            {file? : Path to the Excel pricing file (default: root xlsx)}
                            {--fix-suspicious : Auto-correct 5188 → 51.88 for 215/55/16 97w}
                            {--merge-duplicates : When Excel has duplicate sizes, sum stock and use first price}
                            {--dry-run : Preview all changes without writing to the database}';

    protected $description = 'Sync Rapid tyre stock and prices from the Excel pricing file';

    private const DEFAULT_FILE = 'Copy of Okelcor Assets Value being Held by Demir Keramic in Solnhofen (1) (1).xlsx';

    // ------------------------------------------------------------------
    // Entry point
    // ------------------------------------------------------------------

    public function handle(): int
    {
        $filePath  = $this->argument('file') ?? base_path(self::DEFAULT_FILE);
        $dryRun    = $this->option('dry-run');
        $fixSusp   = $this->option('fix-suspicious');
        $mergeDups = $this->option('merge-duplicates');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN mode — no database changes will be made.');
        }

        $this->line('');
        $this->info('Loading: ' . basename($filePath));

        // ------------------------------------------------------------------
        // 1. Parse raw rows from Excel
        // ------------------------------------------------------------------
        $rawRows = $this->parseExcel($filePath);

        if (empty($rawRows)) {
            $this->error('No Rapid rows found in spreadsheet.');
            return self::FAILURE;
        }

        $this->info(count($rawRows) . ' Rapid rows read from spreadsheet.');

        // ------------------------------------------------------------------
        // 2. Detect and report issues (data errors, suspicious, duplicates)
        // ------------------------------------------------------------------
        [$dataErrors, $suspicious, $duplicates, $canonicalRows] = $this->analyseRows($rawRows, $fixSusp, $mergeDups);

        $this->printIssues($dataErrors, $suspicious, $duplicates);

        // If there are unresolved duplicates and --merge-duplicates was not passed, ask
        if (! empty($duplicates) && ! $mergeDups) {
            $this->line('');
            $this->warn('Duplicate sizes found. First occurrence will be used and subsequent ones skipped.');
            $this->warn('Re-run with --merge-duplicates to sum stock and use the first occurrence\'s price.');
        }

        // Confirm suspicious rows block import unless --fix-suspicious was given
        $unresolvedSuspicious = array_filter($suspicious, fn ($r) => ! $r['auto_fixed']);
        if (! empty($unresolvedSuspicious)) {
            $this->line('');
            $this->error(count($unresolvedSuspicious) . ' suspicious price(s) will be SKIPPED.');
            $this->error('Re-run with --fix-suspicious to auto-correct them.');
        }

        if ($dryRun) {
            $this->line('');
            $this->printDryRunPreview($canonicalRows);
            return self::SUCCESS;
        }

        // ------------------------------------------------------------------
        // 3. Execute sync
        // ------------------------------------------------------------------
        $this->line('');
        $this->info('=== Syncing to database ===');

        $matched = $created = $updated = 0;

        DB::transaction(function () use ($canonicalRows, &$matched, &$created, &$updated) {
            foreach ($canonicalRows as $row) {
                if ($row['skip']) {
                    continue;
                }

                $product = $this->findProduct($row);

                if ($product) {
                    $matched++;
                    if ($this->updateProduct($product, $row)) {
                        $updated++;
                    }
                } else {
                    $this->createProduct($row);
                    $created++;
                }
            }
        });

        // ------------------------------------------------------------------
        // 4. Final summary
        // ------------------------------------------------------------------
        $finalCount = Product::where('brand', 'Rapid')->whereNull('deleted_at')->count();

        $this->line('');
        $this->info('=== Sync complete ===');
        $this->table(['Metric', 'Count'], [
            ['Products matched (existing)', $matched],
            ['Products created (new)',      $created],
            ['Products updated (changed)',  $updated],
            ['Duplicates in Excel',         count($duplicates)],
            ['Suspicious prices skipped',   count($unresolvedSuspicious)],
            ['Final Rapid product count',   $finalCount],
        ]);

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Excel parsing
    // ------------------------------------------------------------------

    private function parseExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = [];
        $rowNumber   = 0;

        foreach ($sheet->getRowIterator(2) as $row) {
            $rowNumber++;
            $cells = [];
            foreach ($row->getCellIterator('A', 'K') as $cell) {
                $cells[] = $cell->getValue();
            }

            [$brand, $width, $height, $rim, $loadIndex, $speedRating,
                $season, $sizePattern, $stock, $priceEur] = $cells;

            // Skip blank rows and the totals row
            if (! $brand || strtolower(trim((string) $brand)) !== 'rapid') {
                continue;
            }

            $rows[] = [
                'excel_row'    => $rowNumber + 1, // +1 because we started at row 2
                'brand'        => 'Rapid',
                'width'        => trim((string) $width),
                'height'       => trim((string) $height),
                'rim'          => trim((string) $rim),
                'load_index'   => trim((string) $loadIndex),
                'speed_rating' => $speedRating ? strtoupper(trim((string) $speedRating)) : '',
                'season'       => $season ? trim((string) $season) : null,
                'size_pattern' => trim((string) $sizePattern),
                'stock'        => (int) $stock,
                'price'        => (float) $priceEur,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Issue detection + row canonicalisation
    // ------------------------------------------------------------------

    /** @return array{0: array, 1: array, 2: array, 3: array} */
    private function analyseRows(array $rawRows, bool $fixSuspicious, bool $mergeDups): array
    {
        $dataErrors = [];
        $suspicious = [];
        $duplicates = [];
        $seen       = []; // matchKey → first canonical row index
        $canonical  = [];

        foreach ($rawRows as $raw) {
            $row = $raw;

            // ---- 1. Data error: width looks wrong ----
            $widthInt = (int) $row['width'];
            if ($widthInt < 100) {
                // Try to recover from size_pattern
                $fixed = $this->extractWidthFromPattern($row['size_pattern']);
                $dataErrors[] = [
                    'excel_row'     => $row['excel_row'],
                    'size_pattern'  => $row['size_pattern'],
                    'bad_width'     => $row['width'],
                    'corrected_to'  => $fixed ?? 'UNKNOWN',
                ];
                if ($fixed) {
                    $row['width'] = (string) $fixed;
                } else {
                    $row['skip']   = true;
                    $row['skip_reason'] = 'Unparseable width';
                    $canonical[] = $row;
                    continue;
                }
            }

            // ---- 2. Suspicious price ----
            if ($row['price'] > 500) {
                $corrected = round($row['price'] / 100, 2);
                $autoFixed = $fixSuspicious;

                $suspicious[] = [
                    'excel_row'    => $row['excel_row'],
                    'size_pattern' => $row['size_pattern'],
                    'bad_price'    => $row['price'],
                    'likely_price' => $corrected,
                    'auto_fixed'   => $autoFixed,
                ];

                if ($autoFixed) {
                    $row['price'] = $corrected;
                } else {
                    $row['skip']        = true;
                    $row['skip_reason'] = 'Suspicious price — re-run with --fix-suspicious';
                    $canonical[] = $row;
                    continue;
                }
            }

            // ---- 3. Duplicate detection ----
            $key = $this->matchKey($row);

            if (isset($seen[$key])) {
                $firstIdx = $seen[$key];
                $duplicates[] = [
                    'match_key'   => $key,
                    'first_row'   => $canonical[$firstIdx]['excel_row'],
                    'dup_row'     => $row['excel_row'],
                    'first_price' => $canonical[$firstIdx]['price'],
                    'dup_price'   => $row['price'],
                    'first_stock' => $canonical[$firstIdx]['stock'],
                    'dup_stock'   => $row['stock'],
                ];

                if ($mergeDups) {
                    // Merge into first occurrence: sum stock, keep first price
                    $canonical[$firstIdx]['stock'] += $row['stock'];
                    $canonical[$firstIdx]['merge_note'] = 'stock merged from rows '
                        . $canonical[$firstIdx]['excel_row'] . '+' . $row['excel_row'];
                }
                // Either way, skip this duplicate row
                $row['skip']        = true;
                $row['skip_reason'] = 'Duplicate of row ' . $canonical[$firstIdx]['excel_row'];
                $canonical[] = $row;
                continue;
            }

            $row['skip'] = false;
            $seen[$key]  = count($canonical);
            $canonical[] = $row;
        }

        return [$dataErrors, $suspicious, $duplicates, $canonical];
    }

    // ------------------------------------------------------------------
    // Product matching
    // ------------------------------------------------------------------

    private function matchKey(array $row): string
    {
        return implode('|', [
            strtolower($row['width']),
            strtolower($row['height']),
            strtolower($row['rim']),
            strtolower($row['load_index']),
            strtolower($row['speed_rating']),
        ]);
    }

    private function findProduct(array $row): ?Product
    {
        // Primary: match all tyre spec columns
        $product = Product::where('brand', 'Rapid')
            ->where('width',        $row['width'])
            ->where('height',       $row['height'])
            ->where('rim',          $row['rim'])
            ->where('load_index',   $row['load_index'])
            ->where('speed_rating', $row['speed_rating'])
            ->whereNull('deleted_at')
            ->first();

        if ($product) {
            return $product;
        }

        // Fallback: normalised size string match
        $normalizedSize = $this->normalizeSize($row['size_pattern']);
        return Product::where('brand', 'Rapid')
            ->whereRaw('LOWER(REPLACE(size, " ", "")) = ?', [strtolower(str_replace(' ', '', $normalizedSize))])
            ->whereNull('deleted_at')
            ->first();
    }

    // ------------------------------------------------------------------
    // Product create / update
    // ------------------------------------------------------------------

    private function updateProduct(Product $product, array $row): bool
    {
        $changes = [];

        if ((float) $product->price !== $row['price']) {
            $changes['price'] = $row['price'];
        }
        if ($product->stock !== $row['stock']) {
            $changes['stock']    = $row['stock'];
            $changes['in_stock'] = $row['stock'] > 0;
        }
        // Fill in spec columns if they are currently empty
        foreach (['width', 'height', 'rim', 'load_index', 'speed_rating'] as $col) {
            if (empty($product->{$col}) && ! empty($row[$col])) {
                $changes[$col] = $row[$col];
            }
        }

        if (empty($changes)) {
            return false;
        }

        $product->update($changes);
        $label = $row['size_pattern'] ?: $this->buildName($row);
        $this->line("  UPDATED  [{$product->id}] {$label}  " . implode(', ', array_map(
            fn ($k, $v) => "{$k}={$v}",
            array_keys($changes),
            $changes
        )));
        return true;
    }

    private function createProduct(array $row): Product
    {
        $name = $this->buildName($row);
        $sku  = $this->generateSku($row);
        $size = $this->normalizeSize($row['size_pattern']);

        $product = Product::create([
            'sku'          => $sku,
            'brand'        => 'Rapid',
            'name'         => $name,
            'size'         => $size,
            'spec'         => trim($row['load_index'] . $row['speed_rating']),
            'season'       => 'Summer',
            'type'         => 'PCR',
            'price'        => $row['price'],
            'price_b2b'    => null,
            'price_b2c'    => null,
            'description'  => $name . ' tyre.',
            'is_active'    => true,
            'sort_order'   => 0,
            'width'        => $row['width'],
            'height'       => $row['height'],
            'rim'          => $row['rim'],
            'load_index'   => $row['load_index'],
            'speed_rating' => $row['speed_rating'],
            'stock'        => $row['stock'],
            'in_stock'     => $row['stock'] > 0,
        ]);

        $this->line("  CREATED  [{$product->id}] {$name}  price={$row['price']}  stock={$row['stock']}");
        return $product;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildName(array $row): string
    {
        $spec = rtrim($row['load_index'] . $row['speed_rating'], ' ');
        return "Rapid {$row['width']}/{$row['height']}R{$row['rim']} {$spec}";
    }

    private function generateSku(array $row): string
    {
        $base = 'RAPID-'
            . $row['width']
            . $row['height']
            . 'R'
            . strtoupper(str_replace('/', '', $row['rim']))
            . '-'
            . strtoupper(str_replace(['/', ' '], '', $row['load_index']))
            . strtoupper($row['speed_rating']);

        // Ensure uniqueness
        $candidate = $base;
        $suffix    = 1;
        while (Product::where('sku', $candidate)->exists()) {
            $candidate = $base . '-' . $suffix++;
        }
        return $candidate;
    }

    private function normalizeSize(string $pattern): string
    {
        // Remove double slashes
        $pattern = preg_replace('#/+#', '/', trim($pattern));
        // Standardise: 275/30R19 → keep as-is; 275/30/19 → keep; etc.
        return $pattern;
    }

    /**
     * Extract width from a size pattern like "205/50/17 93w" → 205.
     * Returns null if the pattern can't be parsed.
     */
    private function extractWidthFromPattern(string $pattern): ?int
    {
        if (preg_match('#^(\d{3})/#', trim($pattern), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Output helpers
    // ------------------------------------------------------------------

    private function printIssues(array $dataErrors, array $suspicious, array $duplicates): void
    {
        if (! empty($dataErrors)) {
            $this->line('');
            $this->warn('=== Data errors (auto-corrected) ===');
            $this->table(
                ['Excel Row', 'Size Pattern', 'Bad Width', 'Corrected To'],
                array_map(fn ($r) => [
                    $r['excel_row'], $r['size_pattern'], $r['bad_width'], $r['corrected_to'],
                ], $dataErrors)
            );
        }

        if (! empty($suspicious)) {
            $this->line('');
            $this->warn('=== Suspicious prices ===');
            $this->table(
                ['Excel Row', 'Size Pattern', 'Recorded Price', 'Likely Price', 'Status'],
                array_map(fn ($r) => [
                    $r['excel_row'],
                    $r['size_pattern'],
                    '€' . number_format($r['bad_price'], 2),
                    '€' . number_format($r['likely_price'], 2),
                    $r['auto_fixed'] ? 'AUTO-FIXED' : 'SKIPPED',
                ], $suspicious)
            );
        }

        if (! empty($duplicates)) {
            $this->line('');
            $this->warn('=== Duplicate sizes in Excel ===');
            $this->table(
                ['Match Key', 'First Row', 'Dup Row', 'First Price', 'Dup Price', 'First Stock', 'Dup Stock'],
                array_map(fn ($r) => [
                    $r['match_key'],
                    $r['first_row'],
                    $r['dup_row'],
                    '€' . number_format($r['first_price'], 2),
                    '€' . number_format($r['dup_price'], 2),
                    $r['first_stock'],
                    $r['dup_stock'],
                ], $duplicates)
            );
        }
    }

    private function printDryRunPreview(array $canonicalRows): void
    {
        $this->info('=== DRY-RUN preview ===');

        $rows = [];
        foreach ($canonicalRows as $row) {
            if ($row['skip']) {
                $rows[] = ['SKIP', $row['size_pattern'], '-', '-', $row['skip_reason'] ?? ''];
                continue;
            }

            $product = $this->findProduct($row);
            if ($product) {
                $priceChanged = (float) $product->price !== $row['price'];
                $stockChanged = $product->stock !== $row['stock'];
                $action       = ($priceChanged || $stockChanged) ? 'UPDATE' : 'NO-CHANGE';
                $note         = implode(', ', array_filter([
                    $priceChanged ? "price €{$product->price}→€{$row['price']}" : null,
                    $stockChanged ? "stock {$product->stock}→{$row['stock']}" : null,
                ]));
                $rows[] = [$action, $row['size_pattern'], '€' . $row['price'], $row['stock'], $note];
            } else {
                $rows[] = ['CREATE', $row['size_pattern'], '€' . $row['price'], $row['stock'], 'new product'];
            }
        }

        $this->table(['Action', 'Size Pattern', 'Price', 'Stock', 'Notes'], $rows);
    }
}
