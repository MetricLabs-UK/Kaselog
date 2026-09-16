<?php

namespace App\Providers;

use GWSN\FlysystemSharepoint\FlysystemSharepointAdapter;
use GWSN\FlysystemSharepoint\SharepointConnector;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

/**
 * Registers the 'sharepoint' Laravel filesystem driver (config/filesystems.
 * php), backing spatie/laravel-backup's SharePoint destination
 * (config/backup.php) — gwsn/flysystem-sharepoint-adapter has no Laravel
 * integration of its own, unlike most disk drivers.
 *
 * SharepointConnector's constructor makes real, synchronous Microsoft Graph
 * API calls the moment it's instantiated (auth token, then site ID, then
 * drive ID) — there is no lazy connection here. That only happens when
 * something actually resolves Storage::disk('sharepoint') (e.g. a real
 * backup run), never merely from this provider booting, since
 * Storage::extend() only registers a *factory closure* — so registering
 * this provider is itself safe with blank/no credentials. Tests must still
 * never call Storage::disk('sharepoint') un-faked; use Storage::fake
 * ('sharepoint') exactly like any other disk to avoid a real Graph call.
 */
class FlySystemSharepointProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('sharepoint', function ($app, array $config): FilesystemAdapter {
            $adapter = new FlysystemSharepointAdapter(
                new SharepointConnector(
                    $config['tenantId'],
                    $config['clientId'],
                    $config['clientSecret'],
                    $config['sharepointSite'],
                ),
                $config['prefix'] ?? '/',
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });
    }
}
