<?php

namespace App\Enums;

enum BackupExportScope: string
{
    case SingleClient = 'single_client';
    case WholeFirm = 'whole_firm';
}
