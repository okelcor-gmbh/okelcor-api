<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class BackupOkelcor extends Command
{
    protected $signature = 'backup:okelcor
                            {--full  : Include product images (for monthly / off-server archiving)}
                            {--force : Run even when BACKUP_ENABLED=false}';

    protected $description = 'Create a compressed database + file backup of the Okelcor application.';

    public function handle(): int
    {
        if (! config('backup.enabled') && ! $this->option('force')) {
            $this->warn('Backup is disabled (BACKUP_ENABLED=false). Use --force to override.');
            return self::SUCCESS;
        }

        $isFull    = $this->option('full') || config('backup.include_product_images', false);
        $timestamp = now()->format('Y-m-d-Hi');
        $prefix    = $isFull ? 'okelcor-backup-full' : 'okelcor-backup';
        $backupName = "{$prefix}-{$timestamp}.zip";
        $backupsDir = storage_path('app/backups');
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupName;
        $tmpDir     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "okelcor_backup_{$timestamp}";

        if (! is_dir($backupsDir)) {
            mkdir($backupsDir, 0755, true);
        }

        // Build path list — product images only on full runs
        $paths = config('backup.paths', []);
        if ($isFull) {
            $productPath = config('backup.product_images_path', 'storage/app/public/products');
            if ($productPath && ! in_array($productPath, $paths, true)) {
                $paths[] = $productPath;
            }
        }

        $typeLabel = $isFull ? 'FULL (with product images)' : 'daily';
        Log::info('[Backup] Started', ['file' => $backupName, 'type' => $typeLabel]);
        $this->info("Backup started [{$typeLabel}]: {$backupName}");

        // ── Pre-flight: delete stale .part files left by aborted runs ────────
        $partsDeleted = $this->purgeStalePartials($backupsDir);
        if ($partsDeleted > 0) {
            $this->line("  Removed {$partsDeleted} stale .part file(s) from previous failed run(s).");
        }

        // ── Pre-flight: prune BEFORE creating to reclaim space first ──────────
        $pruned = $this->pruneOldBackups($backupsDir);
        if ($pruned > 0) {
            $this->line("  Pruned {$pruned} old backup(s) before creating new archive.");
        }

        // ── Pre-flight: disk space check ──────────────────────────────────────
        try {
            $this->checkDiskSpace($backupsDir, $paths);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            Log::error('[Backup] Aborted — insufficient disk space', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        // Write to a .part file while in progress; rename to .zip only on success.
        // Any crash leaves a .part file that purgeStalePartials() cleans next run.
        $partPath = $backupPath . '.part';
        $tmpFiles = [];

        try {
            // ── 1. MySQL dump ────────────────────────────────────────────────
            mkdir($tmpDir, 0755, true);
            $dumpFile = $tmpDir . DIRECTORY_SEPARATOR . 'database.sql';

            $this->line('  Dumping database…');
            $this->dumpDatabase($dumpFile);
            $tmpFiles[] = $dumpFile;

            $dumpMb = round(filesize($dumpFile) / 1048576, 2);
            $this->line("  <fg=green>✓</> Database dumped ({$dumpMb} MB)");

            // ── 2. Build ZIP (written to .part until complete) ───────────────
            $zip = new ZipArchive();
            if ($zip->open($partPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Cannot create ZIP archive: {$partPath}");
            }

            $zip->addFile($dumpFile, 'database.sql');

            foreach ($paths as $relativePath) {
                $absolutePath = base_path($relativePath);

                if (! is_dir($absolutePath)) {
                    $this->warn("  ⚠ Skipping (not found): {$relativePath}");
                    continue;
                }

                $this->addDirectoryToZip($zip, $absolutePath, $relativePath);
                $this->line("  <fg=green>✓</> Added: {$relativePath}");
            }

            $zip->close();

            // Atomic rename: .part → .zip only after successful close
            if (! rename($partPath, $backupPath)) {
                throw new \RuntimeException("Failed to finalise archive: could not rename .part to .zip");
            }

            $sizeMb = round(filesize($backupPath) / 1048576, 2);

            Log::info('[Backup] Completed', [
                'file'    => $backupName,
                'type'    => $typeLabel,
                'size_mb' => $sizeMb,
                'pruned'  => $pruned,
            ]);

            $this->newLine();
            $this->info("Backup completed successfully.");
            $this->line("  File:    {$backupName}");
            $this->line("  Size:    {$sizeMb} MB");
            $this->line("  Path:    {$backupPath}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            // Clean up the in-progress .part file on failure
            if (file_exists($partPath)) {
                @unlink($partPath);
            }
            if (file_exists($backupPath)) {
                @unlink($backupPath);
            }

            Log::error('[Backup] Failed', [
                'file'  => $backupName,
                'type'  => $typeLabel,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("Backup failed: {$e->getMessage()}");
            return self::FAILURE;

        } finally {
            foreach ($tmpFiles as $f) {
                @unlink($f);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Disk space
    // -------------------------------------------------------------------------

    private function checkDiskSpace(string $backupsDir, array $paths): void
    {
        $freeBytes = disk_free_space($backupsDir);
        if ($freeBytes === false) {
            return; // can't determine — proceed and let ZipArchive fail naturally
        }

        $estimatedBytes = 0;
        foreach ($paths as $relativePath) {
            $absolutePath = base_path($relativePath);
            if (is_dir($absolutePath)) {
                $estimatedBytes += $this->dirSizeBytes($absolutePath);
            }
        }

        $freeMb      = round($freeBytes / 1048576, 1);
        $estimatedMb = round($estimatedBytes / 1048576, 1);

        if ($freeBytes < $estimatedBytes) {
            throw new \RuntimeException(
                "Insufficient disk space: {$freeMb} MB free, ~{$estimatedMb} MB estimated for this backup. " .
                "Reduce BACKUP_RETENTION_DAYS or run backup:okelcor --full off-server."
            );
        }

        if ($freeBytes < $estimatedBytes * 1.5) {
            $this->warn("  ⚠ Disk space is tight: {$freeMb} MB free, ~{$estimatedMb} MB estimated. Proceeding.");
        }
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

    // -------------------------------------------------------------------------
    // Retention — runs BEFORE the new archive is created
    // -------------------------------------------------------------------------

    private function pruneOldBackups(string $backupsDir): int
    {
        $retentionDays = (int) config('backup.retention_days', 2);
        $cutoff        = now()->subDays($retentionDays)->timestamp;
        $pruned        = 0;

        $files = glob($backupsDir . DIRECTORY_SEPARATOR . 'okelcor-backup-*.zip') ?: [];

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $pruned++;
                    Log::info('[Backup] Pruned', ['file' => basename($file)]);
                }
            }
        }

        return $pruned;
    }

    // -------------------------------------------------------------------------
    // Stale partial cleanup
    // -------------------------------------------------------------------------

    private function purgeStalePartials(string $backupsDir): int
    {
        $cutoff  = time() - 3600; // older than 1 hour
        $removed = 0;

        $parts = glob($backupsDir . DIRECTORY_SEPARATOR . 'okelcor-backup-*.zip.part') ?: [];

        foreach ($parts as $part) {
            if (filemtime($part) < $cutoff) {
                if (@unlink($part)) {
                    $removed++;
                    Log::warning('[Backup] Removed stale partial file', ['file' => basename($part)]);
                }
            }
        }

        return $removed;
    }

    // -------------------------------------------------------------------------
    // Database dump
    // -------------------------------------------------------------------------

    private function dumpDatabase(string $outputFile): void
    {
        $mysqldump = $this->findMysqldump();

        if (! $mysqldump) {
            throw new \RuntimeException(
                'mysqldump not found. Install MySQL client tools or set MYSQLDUMP_PATH in .env.'
            );
        }

        $host     = config('database.connections.mysql.host', '127.0.0.1');
        $port     = (int) config('database.connections.mysql.port', 3306);
        $user     = config('database.connections.mysql.username', '');
        $password = (string) config('database.connections.mysql.password', '');
        $database = config('database.connections.mysql.database', '');

        // Write temporary credentials file to keep the password out of the
        // process list (visible via `ps aux`) on multi-user servers.
        $tmpCnf = tempnam(sys_get_temp_dir(), 'okelcor_cnf_');
        file_put_contents($tmpCnf, "[client]\npassword=" . str_replace('"', '\\"', $password) . "\n");
        chmod($tmpCnf, 0600);

        try {
            $cmd = sprintf(
                '%s --defaults-extra-file=%s -h%s -P%d -u%s --single-transaction --routines --triggers %s',
                escapeshellarg($mysqldump),
                escapeshellarg($tmpCnf),
                escapeshellarg($host),
                $port,
                escapeshellarg($user),
                escapeshellarg($database)
            );

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $outputFile, 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($cmd, $descriptors, $pipes);

            if (! is_resource($process)) {
                throw new \RuntimeException('Failed to start mysqldump process.');
            }

            fclose($pipes[0]);
            $stderr   = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    "mysqldump exited with code {$exitCode}: " . trim($stderr)
                );
            }

        } finally {
            @unlink($tmpCnf);
        }
    }

    // -------------------------------------------------------------------------
    // ZIP helpers
    // -------------------------------------------------------------------------

    private function addDirectoryToZip(ZipArchive $zip, string $absoluteDir, string $zipPrefix): void
    {
        $absoluteDir = rtrim(str_replace('\\', '/', realpath($absoluteDir)), '/');
        $zipPrefix   = rtrim(str_replace('\\', '/', $zipPrefix), '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $filePath  = str_replace('\\', '/', $file->getRealPath());
            $entryName = $zipPrefix . '/' . ltrim(substr($filePath, strlen($absoluteDir)), '/');

            $zip->addFile($file->getRealPath(), $entryName);
        }
    }

    // -------------------------------------------------------------------------
    // mysqldump discovery
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

        // Fall back to PATH lookup
        $which  = PHP_OS_FAMILY === 'Windows' ? 'where mysqldump 2>nul' : 'which mysqldump 2>/dev/null';
        $result = trim((string) shell_exec($which));

        if ($result && ! str_contains($result, 'not found') && ! str_contains($result, 'Could not find')) {
            return strtok($result, "\n") ?: null;
        }

        return null;
    }
}
