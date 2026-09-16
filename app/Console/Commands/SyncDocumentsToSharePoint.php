<?php

namespace App\Console\Commands;

use App\Models\GeneratedDocument;
use App\Models\MatterDocument;
use App\Models\PrecedentTemplate;
use App\Models\Tenant;
use App\Notifications\DocumentSyncFailedNotification;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Notifications\Notifiable as BackupNotifiable;
use Throwable;

/**
 * Nightly, incremental — separate from the weekly full DB+files backup
 * (config/backup.php). Uses backed_up_at (added alongside this command) on
 * each of the three file-backed models under the 'documents' disk to only
 * push what's new or changed since the last run, rather than re-uploading
 * every matter document, generated document and precedent template every
 * night.
 *
 * Per-file failures don't abort the run — logged individually and counted,
 * so one bad file doesn't block everything else from syncing that night.
 * The command still exits non-zero (and sends
 * DocumentSyncFailedNotification) if anything failed, so scheduler-level
 * failure alerting (routes/console.php's ->onFailure()) and this command's
 * own aren't fighting each other — both point at the same signal.
 */
class SyncDocumentsToSharePoint extends Command
{
    protected $signature = 'backup:sync-documents';

    protected $description = 'Incrementally sync new or changed matter documents, generated documents, and precedent templates to the SharePoint backup destination';

    /**
     * Model class => the column holding its file's path on the 'documents'
     * disk. Not a shared interface/trait across all three models — they
     * predate this command and use different column names (path vs
     * file_path) for the same concept, so mapped here instead.
     *
     * @var array<class-string<Model>, string>
     */
    private const MODELS = [
        MatterDocument::class => 'path',
        GeneratedDocument::class => 'file_path',
        PrecedentTemplate::class => 'file_path',
    ];

    private const SOURCE_DISK = 'documents';

    private const DESTINATION_DISK = 'sharepoint';

    public function handle(): int
    {
        $synced = 0;
        $failed = 0;
        $lastError = null;

        $previousTenant = CurrentTenant::get();

        try {
            foreach (Tenant::all() as $tenant) {
                CurrentTenant::set($tenant);

                foreach (self::MODELS as $modelClass => $pathColumn) {
                    [$tenantSynced, $tenantFailed, $tenantLastError] = $this->syncModel($modelClass, $pathColumn);

                    $synced += $tenantSynced;
                    $failed += $tenantFailed;
                    $lastError = $tenantLastError ?? $lastError;
                }
            }
        } finally {
            CurrentTenant::set($previousTenant);
        }

        $this->info("Synced {$synced} file(s), {$failed} failure(s).");

        if ($failed > 0) {
            Notification::send(new BackupNotifiable, new DocumentSyncFailedNotification($failed, $synced, $lastError));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{0: int, 1: int, 2: ?string}
     */
    private function syncModel(string $modelClass, string $pathColumn): array
    {
        $records = $modelClass::query()
            ->where(function ($query) {
                $query->whereNull('backed_up_at')
                    ->orWhereColumn('updated_at', '>', 'backed_up_at');
            })
            ->get();

        $synced = 0;
        $failed = 0;
        $lastError = null;

        foreach ($records as $record) {
            try {
                $this->syncFile($record, $pathColumn);
                $record->forceFill(['backed_up_at' => now()])->saveQuietly();
                $synced++;
            } catch (Throwable $exception) {
                $failed++;
                $lastError = $exception->getMessage();

                Log::error("Document sync failed for {$modelClass}#{$record->getKey()}: {$exception->getMessage()}");
            }
        }

        return [$synced, $failed, $lastError];
    }

    private function syncFile(Model $record, string $pathColumn): void
    {
        $path = $record->getAttribute($pathColumn);

        if (blank($path)) {
            return;
        }

        $stream = Storage::disk(self::SOURCE_DISK)->readStream($path);

        if ($stream === null) {
            throw new \RuntimeException("Source file missing on disk '".self::SOURCE_DISK."': {$path}");
        }

        try {
            Storage::disk(self::DESTINATION_DISK)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
