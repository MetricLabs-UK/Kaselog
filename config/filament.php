<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | By uncommenting the Laravel Echo configuration, you may connect Filament
    | to any Pusher-compatible websockets server.
    |
    | This will allow your users to receive real-time notifications.
    |
    */

    'broadcasting' => [

        // Reverb speaks the Pusher wire protocol, so 'pusher' + an explicit
        // host/port pointed at Reverb (rather than a real Pusher cluster) is
        // the broadcaster string Filament's bundled Echo client understands
        // regardless of its pinned laravel-echo version — 'reverb' as a
        // literal broadcaster name is only recognised by laravel-echo
        // >=1.16's own connector, which Filament's vendored copy may
        // predate.
        'echo' => [
            'broadcaster' => 'pusher',
            'key' => env('REVERB_APP_KEY'),
            'wsHost' => env('REVERB_HOST', '127.0.0.1'),
            'wsPort' => env('REVERB_PORT', 8080),
            'wssPort' => env('REVERB_PORT', 8080),
            'authEndpoint' => '/broadcasting/auth',
            'disableStats' => true,
            'encrypted' => true,
            'enabledTransports' => ['ws', 'wss'],
            'forceTLS' => env('REVERB_SCHEME', 'http') === 'https',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | This is the storage disk Filament will use to store files. You may use
    | any of the disks defined in the `config/filesystems.php`.
    |
    */

    'default_filesystem_disk' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Temporary File URL Expiry
    |--------------------------------------------------------------------------
    |
    | When Filament generates temporary URLs for previewing private files
    | (file uploads, image columns, image entries, rich editor attachments,
    | etc.), this value controls how many minutes those URLs remain valid.
    |
    | The generated URL's expiry is rounded up to the end of the hour it
    | falls in, so the effective lifetime will be between this value and
    | this value plus up to 60 minutes.
    |
    */

    'temporary_file_url_expiry_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Assets Path
    |--------------------------------------------------------------------------
    |
    | This is the directory where Filament's assets will be published to. It
    | is relative to the `public` directory of your Laravel application.
    |
    | After changing the path, you should run `php artisan filament:assets`.
    |
    */

    'assets_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Cache Path
    |--------------------------------------------------------------------------
    |
    | This is the directory that Filament will use to store cache files that
    | are used to optimize the registration of components.
    |
    | After changing the path, you should run `php artisan filament:cache-components`.
    |
    */

    'cache_path' => base_path('bootstrap/cache/filament'),

    /*
    |--------------------------------------------------------------------------
    | Livewire Loading Delay
    |--------------------------------------------------------------------------
    |
    | This sets the delay before loading indicators appear.
    |
    | Setting this to 'none' makes indicators appear immediately, which can be
    | desirable for high-latency connections. Setting it to 'default' applies
    | Livewire's standard 200ms delay.
    |
    */

    'livewire_loading_delay' => 'default',

    /*
    |--------------------------------------------------------------------------
    | File Generation
    |--------------------------------------------------------------------------
    |
    | Artisan commands that generate files can be configured here by setting
    | configuration flags that will impact their location or content.
    |
    | Often, this is useful to preserve file generation behavior from a
    | previous version of Filament, to ensure consistency between older and
    | newer generated files. These flags are often documented in the upgrade
    | guide for the version of Filament you are upgrading to.
    |
    */

    'file_generation' => [
        'flags' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Route Prefix
    |--------------------------------------------------------------------------
    |
    | This is the prefix used for the system routes that Filament registers,
    | such as the routes for downloading exports and failed import rows.
    |
    */

    'system_route_prefix' => 'filament',

];
