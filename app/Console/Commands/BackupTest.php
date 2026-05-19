<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class BackupTest extends Command
{
    protected $signature = 'backup:test';

    protected $description = 'Run pre-flight checks to verify the backup system is ready.';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>Okelcor Backup Pre-flight Checks</>');
        $this->line(str_repeat('─', 56));

        $failures = 0;

        // ── 1. Database connection ────────────────────────────────────────────
        try {
            DB::connection()->getPdo();
            $db = config('database.connections.mysql.database');
            $this->line("<fg=green>✓</> Database connection OK ({$db})");
        } catch (\Throwable $e) {
            $this->error("✗ Database connection failed: {$e->getMessage()}");
            $failures++;
        }

        // ── 2. mysqldump binary ───────────────────────────────────────────────
        $mysqldump = $this->findMysqldump();

        if ($mysqldump) {
            $this->line("<fg=green>✓</> mysqldump found: {$mysqldump}");
        } else {
            $this->error('✗ mysqldump not found. Install MySQL client or set MYSQLDUMP_PATH in .env.');
            $failures++;
        }

        // ── 3. PHP ZipArchive extension ───────────────────────────────────────
        if (class_exists(ZipArchive::class)) {
            $this->line('<fg=green>✓</> PHP ZipArchive extension available');
        } else {
            $this->error('✗ PHP ZipArchive extension not available. Install php-zip.');
            $failures++;
        }

        // ── 4. Backup directory writable ──────────────────────────────────────
        $backupsDir = storage_path('app/backups');

        if (! is_dir($backupsDir)) {
            @mkdir($backupsDir, 0755, true);
        }

        if (is_writable($backupsDir)) {
            $this->line("<fg=green>✓</> Backup directory writable: {$backupsDir}");
        } else {
            $this->error("✗ Backup directory not writable: {$backupsDir}");
            $failures++;
        }

        // ── 5. Temp directory writable ────────────────────────────────────────
        $tmpDir = sys_get_temp_dir();

        if (is_writable($tmpDir)) {
            $this->line("<fg=green>✓</> Temp directory writable: {$tmpDir}");
        } else {
            $this->error("✗ Temp directory not writable: {$tmpDir}");
            $failures++;
        }

        // ── 6. proc_open available ────────────────────────────────────────────
        if (function_exists('proc_open')) {
            $this->line('<fg=green>✓</> proc_open() available');
        } else {
            $this->error('✗ proc_open() is disabled. Add it to php.ini enable_functions or contact hosting.');
            $failures++;
        }

        // ── 7. Daily backup paths + estimated size ────────────────────────────
        $this->newLine();
        $this->line('Daily backup paths (product images excluded):');

        $dailyPaths      = config('backup.paths', []);
        $estimatedBytes  = 0;

        foreach ($dailyPaths as $relativePath) {
            $absolutePath = base_path($relativePath);

            if (is_dir($absolutePath)) {
                $bytes  = $this->dirSizeBytes($absolutePath);
                $sizeMb = round($bytes / 1048576, 2);
                $estimatedBytes += $bytes;
                $this->line("  <fg=green>✓</> {$sizeMb} MB  {$relativePath}");
            } else {
                $this->line("  <fg=yellow>⚠</> Not found (will be skipped): {$relativePath}");
            }
        }

        $estimatedMb = round($estimatedBytes / 1048576, 2);
        $this->line("  ─────────────────────────────────────────────");
        $this->line("  Estimated daily archive size: ~{$estimatedMb} MB (+ database dump)");

        // Product images (excluded from daily — informational only)
        $productPath     = config('backup.product_images_path', 'storage/app/public/products');
        $absoluteProduct = base_path($productPath);
        if (is_dir($absoluteProduct)) {
            $productMb = round($this->dirSizeBytes($absoluteProduct) / 1048576, 2);
            $this->line("  <fg=yellow>⚠</> Product images excluded from daily: {$productMb} MB  ({$productPath})");
            $this->line("      Including them would make every backup ~{$productMb} MB larger.");
            $this->line("      Full backup is monthly / manual / off-server only:");
            $this->line("        php artisan backup:okelcor --full");
            $this->line("        # Download archive, then delete it locally before next daily run.");
        }

        // ── 8. Disk space vs estimated size ───────────────────────────────────
        $this->newLine();
        $freeBytes = disk_free_space(storage_path('app'));

        if ($freeBytes !== false) {
            $freeMb = round($freeBytes / 1048576, 2);

            if ($freeBytes < $estimatedBytes) {
                $this->error(
                    "✗ Insufficient disk space: {$freeMb} MB free, ~{$estimatedMb} MB estimated for daily backup. " .
                    "Reduce BACKUP_RETENTION_DAYS or free disk space before running."
                );
                $failures++;
            } elseif ($freeBytes < $estimatedBytes * 1.5) {
                $this->warn(
                    "⚠ Disk space is tight: {$freeMb} MB free, ~{$estimatedMb} MB estimated. " .
                    "Backup may succeed but consider reducing retention (current: " .
                    config('backup.retention_days', 2) . " days)."
                );
            } elseif ($freeMb < 100) {
                $this->warn("⚠ Low disk space: {$freeMb} MB free — ensure enough space for archive creation.");
            } else {
                $this->line("<fg=green>✓</> Disk space: {$freeMb} MB free (~{$estimatedMb} MB estimated for daily backup)");
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->newLine();

        if ($failures === 0) {
            $this->info('All checks passed. Run: php artisan backup:okelcor');
            return self::SUCCESS;
        }

        $this->error("{$failures} check(s) failed. Fix the issues above before running backup:okelcor.");
        return self::FAILURE;
    }

    // -------------------------------------------------------------------------

    private function findMysqldump(): ?string
    {
        $configured = config('backup.mysqldump_path');
        if ($configured && is_executable($configured)) {
            return $configured;
        }

        $candidates = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/mysql/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $which  = PHP_OS_FAMILY === 'Windows' ? 'where mysqldump 2>nul' : 'which mysqldump 2>/dev/null';
        $result = trim((string) shell_exec($which));

        if ($result && ! str_contains($result, 'not found') && ! str_contains($result, 'Could not find')) {
            return strtok($result, "\n") ?: null;
        }

        return null;
    }

    private function dirSizeBytes(string $dir): int
    {
        try {
            $size = 0;
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
            return $size;
        } catch (\Throwable) {
            return 0;
        }
    }
}
