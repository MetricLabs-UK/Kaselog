<?php

namespace App\Enums;

// Google Drive isn't wired up yet (Phase 4) — defined now so
// BackupDestinationConnection's possible values don't need a migration when
// it arrives.
enum BackupDestinationProvider: string
{
    case SharePoint = 'sharepoint';
    case GoogleDrive = 'google_drive';

    public function getLabel(): string
    {
        return match ($this) {
            self::SharePoint => 'SharePoint',
            self::GoogleDrive => 'Google Drive',
        };
    }
}
