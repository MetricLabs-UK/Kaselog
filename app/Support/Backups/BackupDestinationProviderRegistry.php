<?php

namespace App\Support\Backups;

use App\Enums\BackupDestinationProvider;
use App\Support\Backups\GoogleDrive\GoogleDriveBackupProvider;
use App\Support\Backups\SharePoint\SharePointBackupProvider;
use InvalidArgumentException;

/**
 * Mirrors AccountingProviderRegistry — the single place that maps a provider
 * key to its implementation. Both real providers are registered now
 * (Phase 3 SharePoint, Phase 4 Google Drive); a third destination would be
 * one more line here.
 */
final class BackupDestinationProviderRegistry
{
    /**
     * @var array<string, class-string<BackupDestinationProviderContract>>
     */
    private const MAP = [
        'sharepoint' => SharePointBackupProvider::class,
        'google_drive' => GoogleDriveBackupProvider::class,
    ];

    public static function get(BackupDestinationProvider $key): BackupDestinationProviderContract
    {
        $class = self::MAP[$key->value]
            ?? throw new InvalidArgumentException("No BackupDestinationProviderContract registered for '{$key->value}' yet.");

        return app($class);
    }

    public static function isAvailable(BackupDestinationProvider $key): bool
    {
        return array_key_exists($key->value, self::MAP);
    }
}
