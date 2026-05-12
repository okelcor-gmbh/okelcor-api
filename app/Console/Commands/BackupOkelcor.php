<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class BackupOkelcor extends Command
{
    protected $signature = 'backup:okelcor
                            {--force : Run even when BACKUP_ENABLED=false}';

    protected $description = 'Create a compressed database + file backup of the Okelcor application.';

    public function handle(): int
    {
        if (! config('backup.enabled') && ! $this->option('force')) {
            $this->warn('Backup is disabled (BACKUP_ENABLED=false). Use --force to override.');
            return self::SUCCESS;
        }

        $timestamp  = now()->format('Y-m-d-Hi');
        $backupName = "okelcor-backup-{$timestamp}.zip";
        $backupsDir = storage_path('app/backups');
        $backupPath = $backupsDir . DIRECTORY_SEPARATOR . $backupName;
        $tmpDir     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "okelcor_backup_{$timestamp}";

        if (! is_dir($backupsDir)) {
            mkdir($backupsDir, 0755, true);
        }

        Log::info('[Backup] Started', ['file' => $backupName]);
        $this->info("Backup started: {$backupName}");

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

            // ── 2. Build ZIP ─────────────────────────────────────────────────
            $zip = new ZipArchive();
            if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Cannot create ZIP archive: {$backupPath}");
            }

            $zip->addFile($dumpFile, 'database.sql');

            foreach (config('backup.paths', []) as $relativePath) {
                $absolutePath = base_path($relativePath);

                if (! is_dir($absolutePath)) {
                    $this->warn("  ⚠ Skipping (not found): {$relativePath}");
                    continue;
                }

                $this->addDirectoryToZip($zip, $absolutePath, $relativePath);
                $this->line("  <fg=green>✓</> Added: {$relativePath}");
            }

            $zip->close();

            // ── 3. Retention ─────────────────────────────────────────────────
            $pruned = $this->applyRetention($backupsDir, $backupPath);

            $sizeMb = round(filesize($backupPath) / 1048576, 2);

            Log::info('[Backup] Completed', [
                'file'    => $backupName,
                'size_mb' => $sizeMb,
                'pruned'  => $pruned,
            ]);

            $this->newLine();
            $this->info("Backup completed successfully.");
            $this->line("  File:    {$backupName}");
            $this->line("  Size:    {$sizeMb} MB");
            $this->line("  Path:    {$backupPath}");
            if ($pruned > 0) {
                $this->line("  Pruned:  {$pruned} old backup(s) removed");
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            // Clean up partial archive so a corrupted file is never left behind
            if (file_exists($backupPath)) {
                @unlink($backupPath);
            }

            Log::error('[Backup] Failed', [
                'file'  => $backupName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("Backup failed: {$e->getMessage()}");
            return self::FAILURE;

        } finally {
            // Always clean temp files
            foreach ($tmpFiles as $f) {
                @unlink($f);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
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
    // Retention
    // -------------------------------------------------------------------------

    private function applyRetention(string $backupsDir, string $justCreated): int
    {
        $retentionDays = (int) config('backup.retention_days', 14);
        $cutoff        = now()->subDays($retentionDays)->timestamp;
        $pruned        = 0;

        $files = glob($backupsDir . DIRECTORY_SEPARATOR . 'okelcor-backup-*.zip') ?: [];

        foreach ($files as $file) {
            // Never delete the archive we just created
            if (realpath($file) === realpath($justCreated)) {
                continue;
            }

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
