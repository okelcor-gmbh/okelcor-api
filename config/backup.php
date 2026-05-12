<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup enabled
    |--------------------------------------------------------------------------
    | Set BACKUP_ENABLED=false in .env to disable scheduled or manual backups
    | without removing the command. Use --force flag to override.
    */
    'enabled' => env('BACKUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Local backup archives older than this many days are automatically pruned
    | after each successful backup run.
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    | Laravel disk name to resolve the backups directory.
    | Backups are always written to storage/app/backups/ on the local filesystem.
    */
    'disk' => env('BACKUP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | mysqldump binary path
    |--------------------------------------------------------------------------
    | Leave null to auto-detect (/usr/bin/mysqldump, /usr/local/bin/mysqldump,
    | etc.). Set an explicit path if mysqldump is in a non-standard location.
    */
    'mysqldump_path' => env('MYSQLDUMP_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Paths to include in backup archive
    |--------------------------------------------------------------------------
    | Relative to the project root. Non-existent paths are skipped with a
    | warning. The database dump is always included automatically.
    | The .env file and storage/app/backups/ are never included.
    */
    'paths' => [
        'storage/app/private',
        'storage/app/public/invoices',
        'storage/app/public/brands',
        'storage/app/public/products',
        'storage/app/public/promotions',
        'storage/app/public/media',
    ],

];
