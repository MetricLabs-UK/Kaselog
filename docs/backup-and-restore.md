# Backup & Disaster Recovery

Section 12 of the audit. Status: code/config scaffolding complete; the
SharePoint destination and real backup/restore runs are **pending the Azure
AD app details** — see the checklist below. This document itself, and
everything under "What's backed up" / "How it fits together", describes the
built system; the "Restore procedure" has been written but **not yet
executed against a real backup** — do that as the last step once the
checklist is done, and update this note when it has been.

## What's backed up

| What | Where from | Where to |
|---|---|---|
| `counselstone` (main app DB) | `mysql` connection | zip, weekly |
| `kase_audit` (item 14's audit trail) | `audit` connection | same zip, weekly |
| `storage/app` (matter documents, generated documents, precedent templates) | local disk `documents` | same zip, weekly |
| Same three file types, incrementally | local disk `documents` | SharePoint only, nightly |

Two separate mechanisms, on purpose:

- **Weekly full backup** (`php artisan backup:run`, Sundays 02:00) —
  spatie/laravel-backup. One zip containing both database dumps (gzip
  compressed) and every file under `storage/app`, sent to **both** the
  `local` disk and the `sharepoint` disk (`config/backup.php`). Local is
  kept alongside SharePoint deliberately — a restore shouldn't depend
  solely on Graph API/network availability being up at the moment you need
  it.
- **Nightly incremental document sync** (`php artisan backup:sync-documents`,
  daily 01:00) — a bespoke command, not spatie/laravel-backup. Uses the new
  `backed_up_at` column on `matter_documents`, `generated_documents` and
  `precedent_templates` to push only files that are new or changed since
  the last run, to SharePoint only (not local — the weekly job already
  covers local). Keeps SharePoint reasonably current between the weekly
  full runs without re-uploading everything every night.

Deliberately **not** backed up: the application code itself (it's in git)
and `.env` (secrets — see "Encryption" below on why bundling those into the
same archive isn't done without turning encryption on first).

## Retention

`config/backup.php`'s cleanup strategy, tuned for the weekly cadence (the
package's day-granularity tiers don't apply — there's never more than one
backup a day to thin out):

- Every weekly backup kept in full for **12 weeks** (~3 months)
- Then **one per month** kept for **12 months**
- Then **one per year** kept for **2 years**
- Hard ceiling: oldest backups pruned once total size exceeds **5000 MB**

**This is a recommendation, not a confirmed decision** — the 5000 MB ceiling
in particular is a placeholder; it hasn't been checked against what a real
weekly backup actually weighs. Confirm or adjust
`config/backup.php`'s `cleanup.default_strategy` once real backup sizes are
known.

## Alerting

Both `backup:run`/`backup:clean`/`backup:monitor` (spatie/laravel-backup's
own notifications) and `backup:sync-documents` (bespoke
`App\Notifications\DocumentSyncFailedNotification`) send to the **same**
recipient — `config('backup.notifications.mail.to')`, i.e.
`BACKUP_NOTIFICATION_EMAIL` — via
`Spatie\Backup\Notifications\Notifiable`. One inbox to watch, not several.
Mail was chosen over Slack because this app already has a mail pipeline
(chase emails, portal invites); Slack's `config/services.php` slot exists
but has no bot token wired up yet — trivial to add later if wanted (spatie/
laravel-backup supports it natively, `config/backup.php`'s `notifications.
slack`).

**"Failed" vs. "didn't run" are different problems.** A failed run alerts
today (both mechanisms above). Detecting that a scheduled task **silently
stopped running at all** — cron broken, server down, whatever — fundamentally
needs something *outside* this app watching for the absence of an event;
nothing running inside the app can observe its own non-execution.
`spatie/laravel-schedule-monitor` is installed and logs every run/failure
locally (`monitored_scheduled_task*` tables), but its "didn't run" detection
specifically requires an external heartbeat service — the package integrates
with **Oh Dear** (`config/schedule-monitor.php`), though any dead-man's-switch
service (healthchecks.io, Cronitor, etc.) would do the same job. This needs
a decision + an account — see the checklist.

## Manual setup checklist

Nothing below exists yet in this codebase or any live account — all of it
needs a person with the right access.

1. **Azure AD app registration for Microsoft Graph.** Confirm whether the
   app mentioned for the file picker/OneDrive integration already exists.
   - If yes: it needs the **`Sites.ReadWrite.All`** *application* (not
     delegated) permission, with admin consent granted in Azure AD.
   - If no: create one (Azure Portal → App registrations → New
     registration), add that permission, grant admin consent.
   - Either way, generate a **client secret** under Certificates & secrets
     — copy the secret *value* immediately, it's never shown again.
2. **Find the SharePoint site name** (not a URL, not a GUID — the plain
   site name) that `GWSN\FlysystemSharepoint\SharepointConnector` resolves
   via Graph. `docs/backup-and-restore.md`'s adapter, not something to
   guess — see `.env.example`'s comment block for how.
3. **Fill in `.env`**: `SHAREPOINT_TENANT_ID`, `SHAREPOINT_CLIENT_ID`,
   `SHAREPOINT_CLIENT_SECRET`, `SHAREPOINT_SITE`. `SHAREPOINT_PREFIX`
   already defaults to `backups` (the folder within the site's document
   library backups will be written under) — change it if you'd rather use
   a different folder.
4. **Decide on archive encryption.** `BACKUP_ARCHIVE_PASSWORD` is blank —
   leaving it blank means backup zips are **not** encrypted at rest in
   SharePoint. Given this is client/case data for an SRA-regulated firm,
   recommend setting a strong password here before this goes anywhere near
   real data — but it's your call, flagging rather than deciding for you.
5. **Confirm (or change) the retention numbers above** — 12 weekly / 12
   monthly / 2 yearly / 5000 MB ceiling.
6. **Set `BACKUP_NOTIFICATION_EMAIL`** to wherever backup/sync failures
   should actually land. Confirm `MAIL_MAILER` is set to something that
   really sends (it's `log` in local dev today — fine for now, not for
   production).
7. **Choose a "did the schedule actually run" watchdog** (Oh Dear or
   equivalent) and provide its API token — `config/schedule-monitor.php`
   already reads `OH_DEAR_API_TOKEN`/`OH_DEAR_MONITOR_ID` from env, nothing
   else to build once you've picked one.
8. **Verify `mysqldump`/`mysql` are on the PATH** of whatever machine
   actually runs the scheduler in production (spatie/laravel-backup shells
   out to them for the database dump/restore) — not checked here, since
   this dev machine already has them via Laragon.

Once 1–4 are done, the weekly backup and SharePoint destination can be run
for real (the next check-in point) — do that before touching 5–8.

## Restore procedure

**Written, not yet exercised against a real backup** — this needs to be
run for real once a genuine backup exists (see the checklist above), in a
disposable environment, and this note updated to say so once it has been.

### 1. Get the backup zip

- From SharePoint: the site → `SHAREPOINT_PREFIX` folder (default
  `backups`) → the app name (`APP_NAME`, `config/backup.php`'s `backup.name`)
  → the dated zip.
- From local: `storage/app/Kaselog/*.zip` (or wherever `local` disk's
  `documents`/root resolves — check `config/filesystems.php`'s `local` disk
  root, `storage/app/private` by default, backups land under the app-name
  subfolder spatie/laravel-backup creates).

If the archive was encrypted (`BACKUP_ARCHIVE_PASSWORD` set at backup
time), you'll need that same password to open it.

### 2. Unzip it

```
unzip <backup-file>.zip -d /tmp/kase-restore
```

Inside: one `.sql.gz` per backed-up database connection (`mysql` →
`counselstone`, `audit` → `kase_audit`) and a directory tree mirroring
`storage/app`.

### 3. Stand up a disposable environment

Do this against a **throwaway** database and app instance — never restore
directly over a live one as the first attempt.

```
git clone <this repo> kase-restore-test
cd kase-restore-test
composer install
cp .env.example .env
php artisan key:generate
```

Point `.env` at two **fresh, empty** local databases (not `counselstone`/
`kase_audit`) — e.g. `counselstone_restore_test` and `kase_audit_restore_test`
— matching `DB_DATABASE` and `AUDIT_DB_DATABASE`.

### 4. Restore the database dumps

```
gunzip -c /tmp/kase-restore/db-dumps/mysql-<timestamp>.sql.gz | mysql -u root counselstone_restore_test
gunzip -c /tmp/kase-restore/db-dumps/audit-<timestamp>.sql.gz | mysql -u root kase_audit_restore_test
```

(Exact dump filenames depend on `config('backup.backup.database_dump_filename_base')`
— `database`, so named after each database, not the connection — confirm
against what's actually in the unzipped archive.)

### 5. Restore the files

Copy the unzipped `storage/app` tree into the fresh clone's own
`storage/app`, preserving the same relative paths (`matter-docs/{id}/...`,
`generated-documents/...`, `precedent-templates/...`) — the `path`/
`file_path` columns restored into the database in step 4 must line up with
where the files actually land.

### 6. Boot and verify

```
php artisan serve
```

Confirm, concretely — not "it looks fine":

- Log in as a real staff user restored from the dump.
- Open a specific Matter you can identify from the original data and
  confirm its client, documents, and time entries are all present and
  correct.
- Open a Client's client-portal-visible document and confirm it actually
  downloads (proves the file restore, not just the DB restore).
- Check `kase_audit`'s `activity_log` table has real historical rows —
  proves the *second* database restored correctly, not just the main one.
- Confirm tenant isolation still holds — a second tenant's data (if the
  backup had more than one) doesn't leak into the first tenant's admin
  panel view.

Only once all of the above are genuinely checked — not assumed from the
zip "looking right" — is the restore considered proven.
