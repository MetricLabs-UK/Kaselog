<?php

namespace App\Console\Commands;

use App\Models\BackupExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Section 15-adjacent firm-facing backup — a completed export's zip
 * (client documents + data, the same sensitive content this whole area has
 * been careful about elsewhere) sits on the private 'exports' disk for the
 * 7-day window BackupExport::markCompleted() sets. This is what actually
 * removes the file once that window passes; the row itself is kept (with
 * file_path cleared) as a history record rather than deleted outright.
 */
class CleanExpiredBackupExports extends Command
{
    protected $signature = 'backup:clean-expired-exports';

    protected $description = 'Delete the files behind expired self-service backup exports (keeps the row as history)';

    public function handle(): int
    {
        $disk = Storage::disk('exports');
        $cleaned = 0;

        BackupExport::allTenants()
            ->whereNotNull('file_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->each(function (BackupExport $export) use ($disk, &$cleaned): void {
                if ($disk->exists($export->file_path)) {
                    $disk->delete($export->file_path);
                }

                $export->forceFill(['file_path' => null])->saveQuietly();
                $cleaned++;
            });

        $this->info("Cleaned {$cleaned} expired backup export file(s).");

        return self::SUCCESS;
    }
}
