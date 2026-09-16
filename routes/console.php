<?php

use App\Jobs\ProcessLeadNurture;
use App\Jobs\ProcessPaymentChases;
use App\Jobs\PushDailyBackups;
use App\Jobs\RefreshAccountingConnections;
use App\Notifications\DocumentSyncFailedNotification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Spatie\Backup\Notifications\Notifiable as BackupNotifiable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Queued via the database driver (QUEUE_CONNECTION=database). Horizon needs
// Redis and isn't installed — switch the queue connection and add Horizon
// before production if the database driver's throughput becomes a bottleneck.
Schedule::job(new ProcessPaymentChases)->dailyAt('08:00');

Schedule::job(new ProcessLeadNurture)->dailyAt('09:00');

// Section 20 — see the job's own docblock for why this runs unconditionally
// every day rather than only for connections nearing their 60-day refresh-
// token deadline.
Schedule::job(new RefreshAccountingConnections)->dailyAt('07:00');

// Section 19 — closes out stale impersonation requests/grants/sessions
// independent of anyone's next request (see the command's own docblock).
// Short durations throughout that feature (a 60-minute request TTL, a
// 10-minute accepted-but-unentered window, a 30-minute session limit) is
// why this runs every five minutes rather than on a daily/weekly cadence
// like the jobs above.
Schedule::command('app:expire-stale-impersonation-sessions')
    ->everyFiveMinutes()
    ->name('expire-stale-impersonation-sessions');

// Section 12 (Backup & Disaster Recovery). ->name() on each entry is what
// spatie/laravel-schedule-monitor tracks per-task run history/failures
// against (config/schedule-monitor.php) — "did this run at all" still needs
// an external heartbeat service wired up separately (see docs/backup-and-
// restore.md's manual checklist), since nothing running *inside* this app
// can detect its own absence.
//
// Nightly, ahead of the weekly full backup so the two don't contend for the
// 'documents' disk at the same time.
Schedule::command('backup:sync-documents')
    ->dailyAt('01:00')
    ->name('sync-documents-to-sharepoint')
    ->onFailure(function (): void {
        // Belt-and-braces for a hard crash (uncaught exception) that never
        // reached the command's own per-run failure notification — that one
        // reports counts; this one just confirms the run itself blew up.
        Notification::send(new BackupNotifiable, new DocumentSyncFailedNotification(
            failedCount: 0,
            syncedCount: 0,
            lastError: 'The sync-documents command exited with an error before it could report its own failure count — check the log.',
        ));
    });

// DB (counselstone + the audit connection) + files, zipped together, to
// both the local disk and SharePoint (config/backup.php).
Schedule::command('backup:run')
    ->weeklyOn(0, '02:00')
    ->name('weekly-backup')
    ->onFailure(fn () => Artisan::call('backup:monitor'));

// backup:clean prunes per config/backup.php's retention strategy;
// backup:monitor re-checks health and is what actually fires
// UnhealthyBackupWasFoundNotification if something's wrong post-cleanup —
// both scheduled after the backup itself finishes.
Schedule::command('backup:clean')
    ->weeklyOn(0, '03:00')
    ->name('weekly-backup-cleanup');

Schedule::command('backup:monitor')
    ->dailyAt('06:00')
    ->name('daily-backup-health-check');

// Firm-facing self-service backup (distinct from the Section 12 entries
// above, which are Kase's own internal backup) — deletes the files behind
// exports past their 7-day download window; see the command's own docblock.
Schedule::command('backup:clean-expired-exports')
    ->dailyAt('04:00')
    ->name('clean-expired-backup-exports');

// Phase 6 — "automatic daily backup" from the original scope: pushes every
// firm's whole-firm backup to each of their connected, ready destinations.
// End of day, well before the cleanup sweep above.
Schedule::job(new PushDailyBackups)
    ->dailyAt('23:00')
    ->name('daily-firm-backup-push');
