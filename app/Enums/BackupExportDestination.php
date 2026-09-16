<?php

namespace App\Enums;

// SharePoint/GoogleDrive aren't wired up yet (Phase 1 only ever creates
// Download rows) — defined now so the column's possible values don't need a
// migration when Phases 3-5 add them.
enum BackupExportDestination: string
{
    case Download = 'download';
    case SharePoint = 'sharepoint';
    case GoogleDrive = 'google_drive';
}
