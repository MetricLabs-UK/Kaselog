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
use RuntimeException;
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
 * Destination disks come from BACKUP_DISKS — the same env var
 * config/backup.php reads — not a hardcoded provider. 'local' is always
 * skipped (logged, not silently ignored): the source files already live on
 * local storage (the 'documents' disk), so re-writing them to the 'local'
 * backup disk achieves nothing here. If BACKUP_DISKS resolves to nothing
 * but 'local' (the default — e.g. a dev machine with no B2 credentials),
 * the run is a deliberate no-op: nothing is written and backed_up_at is
 * NOT stamped on anything, since no off-site copy was actually made. A
 * silent stamp in that case would be indistinguishable from a real sync in
 * the documents table — see the health-check note in
 * docs/backup-and-restore.md.
 *
 * Per-file failures don't abort the run — logged individually and counted,
 * so one bad file doesn't block everything else from syncing that night.
 * The command still exits non-zero (and sends
 * DocumentSyncFailedNotification) if anything failed, so scheduler-level
 * failure alerting (routes/console.php's ->onFailure()) and this command's
 * own aren't fighting each other — both point at the same signal.
 * backed_up_at is only stamped once every configured remote disk has
 * confirmed the write; a failure on any one of them (or an exception
 * thrown by the disk's Flysystem adapter) leaves it unstamped so the next
 * run retries it, and is logged at error level.
 */
class SyncDocumentsToBackupDisk extends Command
{
    protected $signature = 'backup:sync-documents';

    protected $description = 'Incrementally sync new or changed matter documents, generated documents, and precedent templates to the configured backup disk(s)';

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

    public function handle(): int
    {
        $remoteDisks = $this->remoteDisks();

        if ($remoteDisks === []) {
            Log::warning('backup:sync-documents: no off-site backup disk configured (BACKUP_DISKS resolves to local only) — nothing synced.');

            $this->info('No off-site backup disk configured (BACKUP_DISKS=local); nothing to sync.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;
        $lastError = null;

        $previousTenant = CurrentTenant::get();

        try {
            foreach (Tenant::all() as $tenant) {
                CurrentTenant::set($tenant);

                foreach (self::MODELS as $modelClass => $pathColumn) {
                    [$tenantSynced, $tenantFailed, $tenantLastError] = $this->syncModel($modelClass, $pathColumn, $remoteDisks);

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
     * The same BACKUP_DISKS resolution config/backup.php uses, minus
     * 'local' — logged once per run when present so an operator can see it
     * was deliberately skipped rather than silently ignored.
     *
     * @return array<int, string>
     */
    private function remoteDisks(): array
    {
        $disks = array_values(array_filter(array_map('trim',
            explode(',', env('BACKUP_DISKS') ?: 'local')
        )));

        if (in_array('local', $disks, true)) {
            Log::info("backup:sync-documents: skipping disk 'local' — source documents already reside on local storage.");
        }

        return array_values(array_filter($disks, fn (string $disk): bool => $disk !== 'local'));
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, string>  $remoteDisks
     * @return array{0: int, 1: int, 2: ?string}
     */
    private function syncModel(string $modelClass, string $pathColumn, array $remoteDisks): array
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
                $this->syncFile($record, $pathColumn, $remoteDisks);
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

    /**
     * @param  array<int, string>  $remoteDisks
     */
    private function syncFile(Model $record, string $pathColumn, array $remoteDisks): void
    {
        $path = $record->getAttribute($pathColumn);

        if (blank($path)) {
            return;
        }

        // A fresh readStream() per destination disk — writeStream() consumes
        // the stream, so it can't be reused across multiple targets. Any
        // disk whose write throws (e.g. the 's3' disk's 'throw' => true)
        // aborts the remaining disks for this file and propagates to the
        // caller, which logs it and leaves backed_up_at unstamped.
        foreach ($remoteDisks as $disk) {
            $stream = Storage::disk(self::SOURCE_DISK)->readStream($path);

            if ($stream === null) {
                throw new RuntimeException("Source file missing on disk '".self::SOURCE_DISK."': {$path}");
            }

            try {
                Storage::disk($disk)->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
