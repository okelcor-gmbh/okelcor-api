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
    | BEFORE each new backup runs. Keep this low on shared hosting — each
    | archive is several GB and disk quota is finite.
    | Default: 1 day (keeps only today's backup on shared hosting).
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 1),

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
    | Daily backup paths
    |--------------------------------------------------------------------------
    | These paths are always included in every backup (daily and full).
    | Relative to the project root. Non-existent paths are skipped with a
    | warning. The database dump is always included automatically.
    | The .env file and storage/app/backups/ are never included.
    |
    | Product images are intentionally excluded from the daily backup because
    | the directory is several GB and would exhaust shared-hosting disk quota.
    | Use: php artisan backup:okelcor --full   for a one-off full archive.
    */
    'paths' => [
        'storage/app/private',
        'storage/app/public/invoices',
        'storage/app/public/brands',
        'storage/app/public/promotions',
        'storage/app/public/media',
    ],

    /*
    |--------------------------------------------------------------------------
    | Product images path
    |--------------------------------------------------------------------------
    | Included only when BACKUP_INCLUDE_PRODUCT_IMAGES=true or --full is passed.
    | On shared hosting this should be backed up manually/monthly and stored
    | off-server (S3, local machine) rather than kept on the same disk.
    */
    'product_images_path' => 'storage/app/public/products',

    /*
    |--------------------------------------------------------------------------
    | Include product images in every backup
    |--------------------------------------------------------------------------
    | false (default) — daily scheduled backups exclude product images.
    | true            — every backup includes them (use only if disk allows it).
    | Override per-run without changing this flag: php artisan backup:okelcor --full
    */
    'include_product_images' => env('BACKUP_INCLUDE_PRODUCT_IMAGES', false),

];
