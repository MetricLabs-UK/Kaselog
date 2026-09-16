<?php

namespace App\Jobs;

use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Enums\BackupExportStatus;
use App\Models\BackupDestinationConnection;
use App\Models\BackupExport;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 6 — the "automatic daily backup" from the original scope: for every
 * connection that's actually ready to use (BackupDestinationConnection::
 * hasSiteSelected() — a connection still awaiting site/drive selection is
 * silently skipped, not treated as a failure), creates a whole-firm-scoped
 * BackupExport targeting that destination and lets the ordinary
 * GenerateBackupExport queue pipeline handle it exactly like a manual
 * request. A firm with both SharePoint and Google Drive connected gets two
 * separate exports, one per destination, per the original "pushes to the
 * firm's connected destination(s)" (plural) requirement.
 *
 * Deliberately a full whole-firm bundle every night, not an incremental
 * sync — this feature has no per-file change-tracking anywhere (unlike
 * Section 12's SyncDocumentsToSharePoint, which uses backed_up_at). If
 * nightly full re-uploads become a real size/time concern for a large
 * firm, that's the pattern to borrow; not built now since nothing asked
 * for it yet.
 *
 * requested_by_user_id is set to the connection's connected_by — not
 * because a human requested tonight's run, but so BackupExportFailedNotification
 * still reaches someone if it fails (GenerateBackupExport only notifies
 * when an export has a requester). BackupExportReadyNotification fires the
 * same way on success, meaning a successful night currently still sends a
 * "ready" email — same notify-on-both-outcomes behaviour Phase 5 already
 * has for a human's own cloud-destination request. If nightly success
 * emails turn out to be noise, silencing the ready notification
 * specifically for an unattended run is a small follow-up, not a redesign.
 */
class PushDailyBackups implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $previousTenant = CurrentTenant::get();

        try {
            BackupDestinationConnection::allTenants()
                ->get()
                ->filter(fn (BackupDestinationConnection $connection) => $connection->hasSiteSelected())
                ->each(function (BackupDestinationConnection $connection): void {
                    CurrentTenant::set($connection->tenant);

                    try {
                        $export = BackupExport::create([
                            'requested_by_user_id' => $connection->connected_by,
                            'scope' => BackupExportScope::WholeFirm,
                            'client_id' => null,
                            'destination' => BackupExportDestination::from($connection->provider->value),
                            'status' => BackupExportStatus::Pending,
                        ]);

                        GenerateBackupExport::dispatch($export->id);
                    } catch (Throwable $exception) {
                        Log::error("PushDailyBackups: failed to queue a backup for tenant {$connection->tenant_id}, provider {$connection->provider->value}: {$exception->getMessage()}");
                    }
                });
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }
}
