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
        $enabled   = config('backup.enabled') ? '<fg=green>yes</>' : '<fg=yellow>no (BACKUP_ENABLED=false)</>';
        $retention = config('backup.retention_days', 14);
        $this->line("Backup enabled:     {$enabled}");
        $this->line("Retention policy:   keep last {$retention} days");
        $this->newLine();

        if (! is_dir($backupsDir)) {
            $this->warn('Backup directory does not exist yet.');
            $this->line("  Expected path: {$backupsDir}");
            $this->line('  Run: php artisan backup:okelcor');
            $this->newLine();
            return self::SUCCESS;
        }

        $files = glob($backupsDir . DIRECTORY_SEPARATOR . 'okelcor-backup-*.zip') ?: [];

        if (empty($files)) {
            $this->warn('No backup archives found in: ' . $backupsDir);
            $this->line('  Run: php artisan backup:okelcor');
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

        $this->line('Last backup');
        $this->line("  Time:   {$lastTime->toDateTimeString()} ({$lastTime->diffForHumans()})");
        $this->line("  File:   " . basename($latest));
        $this->line("  Size:   {$sizeMb} MB");
        $this->line("  Path:   {$latest}");
        $this->newLine();

        $this->line("Stored backups:     {$count}");

        if ($freeMb !== null) {
            $freeLabel = $freeMb < 100
                ? "<fg=yellow>{$freeMb} MB (low)</>‌"
                : "{$freeMb} MB";
            $this->line("Free disk space:    {$freeLabel}");
        }

        $this->newLine();

        // Full listing table
        $rows = array_map(function (string $f): array {
            $mtime = filemtime($f);
            return [
                basename($f),
                round(filesize($f) / 1048576, 2) . ' MB',
                Carbon::createFromTimestamp($mtime)->toDateTimeString(),
            ];
        }, $files);

        $this->table(['Filename', 'Size', 'Created At'], $rows);

        return self::SUCCESS;
    }
}
