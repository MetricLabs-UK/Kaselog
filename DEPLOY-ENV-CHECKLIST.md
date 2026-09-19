# Production `.env` checklist — Kaselog VPS deploy

Built 2026-08-06 by cross-referencing every `env()` call in `app/`, `config/`, `database/`,
`routes/`, and `bootstrap/` against `.env`/`.env.example`. Variables not listed here (Redis,
Memcached, Postmark/Resend, the non-Ollama Prism providers, SQS/Beanstalkd, Slack logging)
are framework/package boilerplate the app never uses with current drivers — safe to omit entirely.
AWS_* is no longer in that "safe to omit" bucket: the app now uses the S3-compatible `s3` disk
(Backblaze B2) for Section 12 off-site backups — see §2 below.

## 1. Must be set fresh for production — do not copy from dev

| Variable | Purpose | Production value |
|---|---|---|
| `APP_ENV` | Environment gate (Filament auth, error verbosity) | `production` |
| `APP_DEBUG` | Stack-trace visibility | `false` (a boot guard also force-disables it on a non-local `APP_URL`, verified live — but set it correctly anyway) |
| `APP_URL` | Canonical URL; forces HTTPS root URL; drives the debug guard | `https://kaselog.co.uk` (final domain) |
| `APP_KEY` | Encryption/session key | Generate ON the VPS: `php artisan key:generate`. Never reuse the dev key |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL credentials | New credentials created on the VPS. Dev values are local-only |
| `DB_HOST` / `DB_PORT` / `DB_CONNECTION` | | `127.0.0.1` / `3306` / `mysql` (same as dev unless DB is remote) |

## 2. Needs Andy's real values — BLOCKERS or feature-degrading if missing

| Variable | Purpose | Status |
|---|---|---|
| `MAIL_MAILER` + `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_FROM_ADDRESS` | **BLOCKER for portal invites, payment-chase emails, lead-nurture emails.** Currently `MAIL_MAILER=log` — every email silently goes to the log file. Plan per `.env.example`: M365 SMTP (`smtp.office365.com:587`, already preset). Needs the firm mailbox address, its **app password** (not sign-in password, if MFA), and "Authenticated SMTP" enabled for that mailbox in the Exchange admin center | Waiting on M365 credentials |
| `XERO_WEBHOOK_SECRET` | Xero payment webhook signature check. **Currently empty — the endpoint 401s everything until set**, so payments will not auto-reconcile | From the Xero developer portal when the webhook is registered against the prod URL |
| `FIRM_ADDRESS` / `FIRM_PHONE` / `FIRM_EMAIL` | Read **once, at migration time**, to stamp the firm's contact details onto the root tenant for generated documents. Without them the tenant gets placeholder values (`1 Example Street…`) | Set real values in `.env` **before running `php artisan migrate`**, or update the `tenants` row afterwards |
| `RETELL_API_KEY` | Verifies Retell webhook signatures (Retell signs with the account API key) | Dev `.env` already holds a real-looking key — confirm it's the production account's key |
| `BACKUP_DISKS` | **BLOCKER.** Which disks `config/backup.php` (weekly full backup) and the nightly `backup:sync-documents` command actually write/monitor to. Default is `local` alone. **Leaving it at `local` in production means the nightly document sync is a deliberate no-op — nothing is written to B2 and `backed_up_at` is not stamped on any document, generated document, or precedent template** (see `SyncDocumentsToBackupDisk`'s docblock) | Production value: `local,s3` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | B2 application key for the `s3` disk (config/filesystems.php) that Section 12 backups now write to | **Must be a B2 application key scoped to the single backup bucket — never the account's master application key.** From the B2 dashboard: App Keys > Add a New Application Key, restricted to the bucket named in `AWS_BUCKET` |
| `AWS_DEFAULT_REGION` / `AWS_BUCKET` / `AWS_ENDPOINT` | B2 bucket region, name, and S3-compatible endpoint | From the bucket's "Endpoint" on its B2 bucket details page, e.g. region `eu-central-003`, endpoint `https://s3.eu-central-003.backblazeb2.com` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Request style for the `s3` disk | `false` — B2's S3-compatible endpoint uses virtual-hosted-style requests |
| `BACKUP_ARCHIVE_PASSWORD` | **BLOCKER.** Archive encryption password for `config/backup.php`. Blank disables encryption entirely on backups that now leave the server for B2 | Set a strong value before this ships anywhere near production data |
| `BACKUP_NOTIFICATION_EMAIL` | **BLOCKER.** Where backup/cleanup/health-check and document-sync failure notifications go. Falls back to a placeholder `your@example.com` if blank | Set to an address someone actually monitors |

`SHAREPOINT_TENANT_ID` / `SHAREPOINT_CLIENT_ID` / `SHAREPOINT_CLIENT_SECRET` / `SHAREPOINT_SITE` /
`SHAREPOINT_PREFIX` are **obsolete** — they belonged to Section 12's old SharePoint destination
(`SyncDocumentsToSharePoint`, removed) which B2/the `s3` disk has replaced. They can be left blank
or dropped from `.env` entirely. Do **not** confuse these with `AZURE_BACKUP_CLIENT_ID` /
`AZURE_BACKUP_CLIENT_SECRET`, which power the separate firm-facing self-service backup export
(`SharePointBackupProvider`, a different multi-tenant Azure AD app registration with delegated
permissions) — that feature is unrelated to Section 12 and unaffected by this change.

## 3. Carry over as-is (environment-agnostic, verified working)

| Variable | Value | Note |
|---|---|---|
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` | Tables exist in migrations (verified in the from-zero run). **A queue worker must run on the VPS** (`php artisan queue:work` under systemd/supervisor) or document AI summaries hang at "Processing…" and Xero payment jobs never execute |
| `FILESYSTEM_DISK` | `local` | Documents disk is `storage/app` (private) |
| `SCOPE_SOLICITORS` | `false` | Business toggle, default off |
| `PORTAL_INVITE_EXPIRY_HOURS` | omit (defaults 72) | Optional |
| `BCRYPT_ROUNDS` | `12` | |
| `APP_NAME` | `Kaselog` | |
| `RETELL_WEBHOOK_SECRET` | can stay empty | Defined in config for compatibility; **not** used for signature verification |

## 4. Local-only values that must change

| Variable | Dev value | Production value |
|---|---|---|
| `OLLAMA_URL` | `http://10.10.0.1:11434` (dev-side WireGuard address) | The Ollama box's WireGuard tunnel address **as seen from the VPS** once the VPS-side tunnel is up. If unreachable, AI features fail gracefully (verified) but are dead |
| `OLLAMA_MODEL` | `qwen3:8b` | Same, unless the model changes |
| `LOG_LEVEL` / `LOG_CHANNEL` | `debug` / `stack`→`single` | Recommend `info` and `daily` (or keep `single` if preferred) |
| `SESSION_SECURE_COOKIE` | unset | Recommend `true` behind HTTPS |

## 5. Beyond `.env` — deploy steps surfaced by the from-zero verification

1. `php artisan migrate --force` — **verified clean from an empty MySQL 8.4 database (45 migrations)**. Seeds the 3 tenants itself.
2. `php artisan db:seed --force` — one-time. Creates the director login (prints a generated password ONCE — capture it) and the 6 precedent-template records. Verified from zero after fixing a fresh-DB seeder crash (see git-less change log / session notes).
3. **Precedent template files: BLOCKER for document generation.** The 6 seeded records point at `storage/app/precedent-templates/{key}.docx` — those files do not exist (on dev either; only unreferenced upload files are present). Andy must supply the six real `.docx` templates: either drop them in with those exact names, or upload each via the admin UI. Until then "Generate Document" fails with a clean error notification.
4. `php artisan storage:link` — tenant logos are served from the `public` disk.
5. Copy nothing from dev `storage/app` except any real template/document files that should carry over.
6. Register the Xero webhook + Retell webhook against the production URLs (`/webhooks/xero`, `/retell/webhook`) and fill the secrets above.
7. **`php artisan schedule:run` must be registered in cron to fire every minute** (`* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1`). Every scheduled entry in `routes/console.php` — payment chases, lead nurture, the nightly document sync, the weekly backup + cleanup + health check, impersonation expiry — depends on this one cron line. Without it, nothing in that file ever executes, silently.
