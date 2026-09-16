<?php

namespace App\Jobs;

use App\Enums\BackupDestinationProvider;
use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Enums\BackupExportStatus;
use App\Models\BackupDestinationConnection;
use App\Models\BackupExport;
use App\Models\Client;
use App\Models\Matter;
use App\Notifications\BackupExportFailedNotification;
use App\Notifications\BackupExportReadyNotification;
use App\Support\Backups\BackupDestinationProviderRegistry;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Firm-facing self-service backup (distinct from Section 12's Kase-internal
 * one) — bundles every document across a client's matters (BackupExportScope
 * ::SingleClient) or every matter in the firm (::WholeFirm, Phase 2) plus a
 * CSV of key matter/client fields into one zip. Phase 5: a single-client
 * export can be pushed straight to a connected SharePoint/Google Drive
 * instead of staying a local download — see pushToCloudDestination(). Phase
 * 6's PushDailyBackups creates the other combination this supports: an
 * unattended, whole-firm-scoped export pushed to a cloud destination. A
 * cloud-pushed export never keeps a local copy around: markCompleted() gets
 * a null file_path, so BackupExport::isDownloadable() correctly shows no
 * download link for it, and there's nothing for the daily cleanup sweep to
 * expire.
 *
 * Only the export's id is carried in the payload, not the model — same
 * reasoning as SummarizeMatterDocument: a queue worker has no ambient tenant
 * context, so it must be re-derived from the record itself before the
 * fail-closed TenantScope can resolve anything.
 *
 * Every file is read via Storage::get() (never addFile() with a resolved
 * local path) so this keeps working unchanged if the 'documents' disk ever
 * moves off local storage.
 *
 * A whole-firm export streams matters via cursor() rather than get() — a
 * single-client export is always bounded by one client's own matter count,
 * but "every matter in the firm" is exactly the volume this phase exists to
 * prove works without loading the entire firm into memory at once. Matters
 * and their documents are handled in one pass (CSV row + zip entries
 * together) rather than iterating twice, since a LazyCollection re-runs its
 * underlying query on each separate iteration.
 */
class GenerateBackupExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $backupExportId) {}

    public function handle(): void
    {
        $export = BackupExport::allTenants()->find($this->backupExportId);

        if (! $export) {
            Log::warning("GenerateBackupExport: backup export {$this->backupExportId} no longer exists.");

            return;
        }

        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($export->tenant);

        try {
            $this->process($export);
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }

    private function process(BackupExport $export): void
    {
        $export->markProcessing();

        $disk = Storage::disk('exports');
        $relativePath = $export->tenant_id.'/'.Str::uuid().'.zip';

        // Built directly on the exports disk's own path, not a temp file
        // copied in afterward — unlike the 'documents' disk this job reads
        // from (which must stay storage-agnostic), 'exports' is defined as
        // always-local scratch space, so ZipArchive can safely address it
        // by real filesystem path.
        $disk->makeDirectory((string) $export->tenant_id);
        $absolutePath = $disk->path($relativePath);

        $csvHandle = fopen('php://temp', 'w+');
        $zip = null;

        try {
            $zip = new ZipArchive;

            if ($zip->open($absolutePath, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Could not create the zip archive.');
            }

            fputcsv($csvHandle, [
                'Client name', 'Client email', 'Client phone', 'Client address',
                'Matter reference', 'Title', 'Practice area', 'Status', 'URN', 'Court name',
                'Instruction date', 'Limitation date', 'Court date', 'Closed date', 'Agreed fee', 'Notes',
            ]);

            foreach ($this->resolveMatters($export) as $matter) {
                $this->writeCsvRow($csvHandle, $matter);
                $this->addMatterDocuments($zip, $matter);
            }

            rewind($csvHandle);
            $zip->addFromString('matters.csv', stream_get_contents($csvHandle));

            $zip->close();

            $fileSize = filesize($absolutePath);

            if ($export->destination === BackupExportDestination::Download) {
                $export->markCompleted($relativePath, $fileSize);
            } else {
                // Reached by a user-requested single-client export choosing
                // a cloud destination (Phase 5), or a Phase 6 automatic
                // daily push, which is always whole-firm scope — the only
                // path that combines WholeFirm with a cloud destination.
                // Manually requesting a whole-firm download never offers a
                // cloud option (RequestWholeFirmBackupAction hardcodes
                // Download), so this is never reached by a human choosing
                // it for a whole-firm export.
                $this->pushToCloudDestination($export, $absolutePath);
                $disk->delete($relativePath);
                $export->markCompleted(null, $fileSize);
            }

            if ($export->requestedBy) {
                Notification::send($export->requestedBy, new BackupExportReadyNotification($export));
            }
        } catch (Throwable $exception) {
            Log::error("GenerateBackupExport: export {$export->id} failed: {$exception->getMessage()}");

            // Windows won't allow deleting a file with a still-open handle —
            // close it first regardless of how far the try block got. A
            // failure past the zip's own successful close() (e.g. the
            // cloud upload step) reaches here with $zip already closed —
            // ext-zip throws ValueError on a second close(), which would
            // otherwise mask the real exception being handled.
            if ($zip instanceof ZipArchive) {
                try {
                    $zip->close();
                } catch (\ValueError) {
                    // Already closed — nothing left to do.
                }
            }

            if ($disk->exists($relativePath)) {
                $disk->delete($relativePath);
            }

            $export->markFailed('Something went wrong while preparing this backup. Try again, or contact support if it keeps happening.');

            if ($export->requestedBy) {
                Notification::send($export->requestedBy, new BackupExportFailedNotification($export));
            }
        } finally {
            fclose($csvHandle);
        }
    }

    /**
     * @return iterable<int, Matter>
     */
    private function resolveMatters(BackupExport $export): iterable
    {
        return match ($export->scope) {
            BackupExportScope::SingleClient => $this->clientForExport($export)->matters()->with('client')->get(),
            BackupExportScope::WholeFirm => Matter::query()->with('client')->cursor(),
        };
    }

    private function clientForExport(BackupExport $export): Client
    {
        return $export->client ?? throw new RuntimeException('Single-client export has no client.');
    }

    /**
     * Phase 5 — pushes the finished zip to the firm's connected SharePoint/
     * Google Drive rather than leaving it for direct download. Re-checks
     * the connection is still ready (not just relying on it having been
     * ready when the export was requested) since a firm could disconnect
     * or lose their selected site/drive in the time it took this queued job
     * to run.
     */
    private function pushToCloudDestination(BackupExport $export, string $absolutePath): void
    {
        $providerKey = BackupDestinationProvider::from($export->destination->value);
        $connection = BackupDestinationConnection::forTenantAndProvider($export->tenant, $providerKey);

        if (! $connection || ! $connection->hasSiteSelected()) {
            throw new RuntimeException("The {$providerKey->getLabel()} connection is no longer ready — reconnect it and try again.");
        }

        $label = match ($export->scope) {
            BackupExportScope::SingleClient => $this->clientForExport($export)->full_name,
            BackupExportScope::WholeFirm => 'Whole Firm',
        };
        $remotePath = 'Kase Backups/'.Str::slug($label).'-'.now()->format('Ymd-His').'.zip';

        BackupDestinationProviderRegistry::get($providerKey)->uploadFile($connection, $remotePath, file_get_contents($absolutePath));
    }

    /**
     * @param  resource  $handle
     */
    private function writeCsvRow($handle, Matter $matter): void
    {
        fputcsv($handle, [
            $matter->client?->full_name,
            $matter->client?->email,
            $matter->client?->phone,
            $matter->client?->address,
            $matter->reference,
            $matter->title,
            $matter->practice_area,
            $matter->status->value,
            $matter->urn,
            $matter->court_name,
            $matter->instruction_date?->toDateString(),
            $matter->limitation_date?->toDateString(),
            $matter->court_date?->toDateString(),
            $matter->closed_date?->toDateString(),
            $matter->agreed_fee,
            $matter->notes,
        ]);
    }

    private function addMatterDocuments(ZipArchive $zip, Matter $matter): void
    {
        $folder = Str::slug($matter->reference ?: "matter-{$matter->id}");
        $disk = Storage::disk('documents');

        foreach ($matter->documents as $document) {
            if (blank($document->path) || ! $disk->exists($document->path)) {
                continue;
            }

            $zip->addFromString("{$folder}/documents/{$document->filename}", $disk->get($document->path));
        }

        foreach ($matter->generatedDocuments as $generated) {
            if (blank($generated->file_path) || ! $disk->exists($generated->file_path)) {
                continue;
            }

            $zip->addFromString("{$folder}/generated/{$generated->filename}", $disk->get($generated->file_path));
        }
    }

    /**
     * Leaves the export in a resolved (failed) state rather than stuck
     * "processing" forever if handle() itself throws past retries.
     */
    public function failed(?Throwable $exception): void
    {
        $export = BackupExport::allTenants()->find($this->backupExportId);

        if (! $export) {
            return;
        }

        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($export->tenant);

        try {
            if ($export->status !== BackupExportStatus::Completed) {
                $export->markFailed('Something went wrong while preparing this backup. Try again, or contact support if it keeps happening.');

                if ($export->requestedBy) {
                    Notification::send($export->requestedBy, new BackupExportFailedNotification($export));
                }
            }
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }
}
