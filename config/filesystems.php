<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Firm-facing self-service backup exports (distinct from Section
         * 12's Kase-internal backup/'sharepoint' disk above) — zips built by
         * App\Jobs\GenerateBackupExport, private and short-lived. Never
         * served directly by URL; always downloaded through an
         * authenticated, tenant-checked Filament action. Swept daily by
         * app:clean-expired-backup-exports once a row's expires_at passes.
         */
        'exports' => [
            'driver' => 'local',
            'root' => storage_path('app/exports'),
            'throw' => false,
            'report' => false,
        ],

        /*
         * spatie/laravel-backup's SharePoint destination (config/backup.php)
         * — driver registered by App\Providers\FlySystemSharepointProvider
         * via Storage::extend(), wrapping gwsn/flysystem-sharepoint-adapter.
         * App-only (client credentials) Graph auth — see .env.example for
         * where each of these comes from in the Azure portal, and the
         * manual setup checklist in docs/backup-and-restore.md.
         */
        'sharepoint' => [
            'driver' => 'sharepoint',
            'tenantId' => env('SHAREPOINT_TENANT_ID'),
            'clientId' => env('SHAREPOINT_CLIENT_ID'),
            'clientSecret' => env('SHAREPOINT_CLIENT_SECRET'),
            'sharepointSite' => env('SHAREPOINT_SITE'),
            'prefix' => env('SHAREPOINT_PREFIX', 'backups'),
        ],

        /*
         * Section 12's Backblaze B2 destination (S3-compatible) for
         * spatie/laravel-backup — see config/backup.php's 'disks' and
         * .env.example for the BACKUP_DISKS/AWS_* setup. 'throw' is
         * deliberately true here (unlike the other disks above): without
         * it Flysystem swallows write failures silently, which on a backup
         * disk means believing backups exist when they don't.
         */
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
