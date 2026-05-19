<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;

class BackupStatus extends Command
{
    protected $signature = 'backup:status';

    protected $description = 'Show the current backup status: last backup time, size, retention, and disk space.';

    public function handle(): int
    {
        $backupsDir = storage_path('app/backups');

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Okelcor Backup Status</>');
        $this->line(str_repeat('─', 56));

        // Config summary
        $enabled        = config('backup.enabled') ? '<fg=green>yes</>' : '<fg=yellow>no (BACKUP_ENABLED=false)</>';
        $retention      = config('backup.retention_days', 2);
        $includeImages  = config('backup.include_product_images', false);
        $imageLabel     = $includeImages
            ? '<fg=yellow>yes (BACKUP_INCLUDE_PRODUCT_IMAGES=true — high disk usage)</>'
            : '<fg=green>no — excluded from daily (use --full for manual run)</>';

        $this->line("Backup enabled:        {$enabled}");
        $this->line("Retention policy:      keep last {$retention} days");
        $this->line("Product images:        {$imageLabel}");
        $this->newLine();

        if (! is_dir($backupsDir)) {
            $this->warn('Backup directory does not exist yet.');
            $this->line("  Expected path: {$backupsDir}");
            $this->line('  Run: php artisan backup:okelcor');
            $this->newLine();
            return self::SUCCESS;
        }

        $files = glob($backupsDir . DIRECTORY_SEPARATOR . 'okelcor-backup-*.zip') ?: [];
        $parts = glob($backupsDir . DIRECTORY_SEPARATOR . 'okelcor-backup-*.zip.part') ?: [];
        $stalePartCount = count(array_filter($parts, fn ($f) => filemtime($f) < time() - 3600));

        // Backup directory total size (all files, including .part)
        $dirTotalBytes = 0;
        foreach (array_merge($files, $parts) as $f) {
            $dirTotalBytes += filesize($f) ?: 0;
        }
        $dirTotalMb = round($dirTotalBytes / 1048576, 2);

        if (empty($files)) {
            $this->warn('No backup archives found in: ' . $backupsDir);
            $this->line('  Run: php artisan backup:okelcor');
            if ($stalePartCount > 0) {
                $this->warn("  {$stalePartCount} stale .part file(s) found — will be cleaned on next run.");
            }
            $this->newLine();
            return self::SUCCESS;
        }

        // Sort newest first
        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

        $latest    = $files[0];
        $lastTime  = Carbon::createFromTimestamp(filemtime($latest));
        $sizeMb    = round(filesize($latest) / 1048576, 2);
        $count     = count($files);
        $freeBytes = disk_free_space($backupsDir);
        $freeMb    = $freeBytes !== false ? round($freeBytes / 1048576, 2) : null;
        $isFull    = str_contains(basename($latest), '-full-');

        $this->line('Last backup');
        $this->line("  Time:   {$lastTime->toDateTimeString()} ({$lastTime->diffForHumans()})");
        $this->line("  File:   " . basename($latest));
        $this->line("  Size:   {$sizeMb} MB");
        $this->line("  Type:   " . ($isFull ? 'full (product images included)' : 'daily (product images excluded)'));
        $this->line("  Path:   {$latest}");
        $this->newLine();

        $this->line("Stored backups:        {$count}");

        $dirLabel = $dirTotalMb > 2000
            ? "<fg=yellow>{$dirTotalMb} MB (backup directory is large)</>"
            : "{$dirTotalMb} MB";
        $this->line("Backup directory size: {$dirLabel}");

        if ($stalePartCount > 0) {
            $this->line("Stale .part files:     <fg=yellow>{$stalePartCount} (will be cleaned on next backup run)</>");
        } else {
            $this->line("Stale .part files:     <fg=green>none</>");
        }

        if ($freeMb !== null) {
            $freeLabel = $freeMb < 500
                ? "<fg=yellow>{$freeMb} MB (low — daily backup may fail)</>"
                : "{$freeMb} MB";
            $this->line("Free disk space:       {$freeLabel}");
        }

        $this->newLine();
        $this->line('<fg=cyan>Tip:</> Monthly full backup (includes product images):');
        $this->line('  php artisan backup:okelcor --full');
        $this->line('  Then download the archive off-server and delete it locally.');
        $this->newLine();

        // Full listing table
        $rows = array_map(function (string $f): array {
            $mtime = filemtime($f);
            $type  = str_contains(basename($f), '-full-') ? 'full' : 'daily';
            return [
                basename($f),
                round(filesize($f) / 1048576, 2) . ' MB',
                $type,
                Carbon::createFromTimestamp($mtime)->toDateTimeString(),
            ];
        }, $files);

        $this->table(['Filename', 'Size', 'Type', 'Created At'], $rows);

        return self::SUCCESS;
    }
}
