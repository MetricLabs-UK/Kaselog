# Kase (Kaselog) Codebase Audit — 2026-08-30

Codebase has moved well past `BRIEF.txt`'s original single-tenant "Counselstone" MVP spec: it's now a multi-tenant "Kaselog" platform with a Phase 3 AI layer (Quill), a live Retell voice-webhook integration, and an AI document pipeline — none of which the brief describes. This audit checked actual code (models, migrations, Filament resources, policies/gates, jobs, services) against both the brief and what's really there, not just filenames.

A prior internal audit (`AUDIT-2026-08-06.md`, 22 findings) exists in the repo. Its highest-severity findings — a live-breaking Xero job crash, a bug that 403'd every staff login in production, and disabled portal accounts not actually being enforced — were verified fixed in current code, each backed by a regression test. The full test suite was run live during this audit: **84/84 tests passing, 280 assertions.**

---

## 1. Matters / Case Management
**Status:** Built

**What's done:**
- `Matter` model (`app/Models/Matter.php`) is far richer than BRIEF.txt's spec: title, URN, court_name, hearing_type, offence_date/location, plea, outcome, sentence, instruction/limitation/closed dates, agreed_fee, plus a compliance checklist (client_care_sent, aml_verified, conflict_checked, gdpr_sent, costs_updated, file_review_done).
- Auto-reference generation (`CS-{YEAR}-{seq}`, tenant-scoped) is transaction-wrapped with `lockForUpdate()` on the tenant row to prevent duplicate references under concurrent creation — verified by `tests/Feature/MatterReferenceGenerationTest.php`.
- `MatterResource` with a real tabbed detail view (`MatterViewTabs`: Summary, Case Details, Finance, Time, Documents, Communications, Ask Quill, Notes).
- `locked`/`director_only` gating on `canEdit`/`canDelete` matches BRIEF.txt's pattern exactly, and is enforced at the **query level** (`getEloquentQuery`), not just the view gate — a non-director can't resolve a director-only matter by direct URL. `director_only` also correctly cascades from a director-only client's matters.
- `SCOPE_SOLICITORS` config flag is genuinely wired: when true, `MatterResource::getEloquentQuery()` filters to `assigned_user_id = auth()->id()` for solicitors.
- Relation managers: TimeEntries, MatterDocuments (upload, `visible_to_client` toggle, AI summary, document generation).

**What's outstanding:**
- No relation manager for PaymentPlan or MatterMessages on the Matter resource itself (PaymentPlan is a separate top-level resource; MatterMessages has no UI at all — see Communications).

**Notes/risks:**
- `Matter::generateReference()` has a documented, low-likelihood race (read-then-increment) — accepted risk at current volume, not fixed.
- `SCOPE_SOLICITORS` only scopes Matters — it does **not** scope TimeEntries (see section 10), which is worth a deliberate sign-off, not an assumed gap.

---

## 2. Client Management
**Status:** Built

**What's done:**
- `Client` model implements `FilamentUser`/`HasTenants`/`Authenticatable` directly — it's also the portal auth principal, with a hashed password cast.
- `portal_token`/`portal_enabled`/`portal_last_login`/`locked`/`director_only` are load-bearing, not decorative: `Client::canAccessPanel()` enforces `portal_enabled && !locked` on every portal request (a previously confirmed gap — flags were display-only — now fixed).
- `ClientResource`: full CRUD, `MattersRelationManager`, row-level `director_only` filtering, `locked`/`director_only` gating on edit/delete.
- `PortalInviteService` generates a fresh signed, expiring (72h) invite link and sends it by email + SMS (SMS leg is a stub — see section 5/16 cross-cutting note).

**What's outstanding:**
- No archive/soft-delete path for clients (see Archive System) — a permitted delete is a hard delete.

**Notes/risks:**
- None beyond what's covered in Compliance & Security (Client is one of the models with **no audit trail** of edits — see section 14).

---

## 3. Client Portal
**Status:** Partially built

**What's done:**
- Real, separate `portal` auth guard and tenant-scoped panel (`PortalPanelProvider`), path-based tenancy at `{tenant-slug}/{matter-reference}`, confirmed disjoint from the staff `web` guard (`GuardIsolationTest`).
- `portal_enabled`/`locked` flags gate login **and** kill an active session mid-request when flipped (`PortalAccessTest` proves both).
- Signed, single-use, 72h-expiring invite links (`SetPortalPassword` flow), tenant/token/matter cross-checked.
- `MatterView` page enforces both fail-closed tenant scoping and per-client ownership (`$matter->client_id !== auth()->guard('portal')->id()` → 404) — a client cannot view another client's matter by guessing/editing a reference in the URL.

**What's outstanding:**
- The client-facing UI is, by its own code comment, "deliberately minimal — proves the routing/auth/tenant-scoping/branding/disclosure chain end-to-end." It renders only matter reference, status, and court date.
- None of the following exist in the portal despite schema support: payment plan/instalments view, documents (`visible_to_client` is toggled by staff but never surfaced to a client anywhere — currently an inert flag), messages (zero UI on either side), client statement writing, document upload by client, profile completion.
- `client_requests` table exists in the DB (migration only) with **no Eloquent model and no UI anywhere** — fully dead schema.

**Notes/risks:**
- This is exactly BRIEF.txt's "Phase 2 — schema built, UI deferred" state, and it's still accurate today. The security/isolation foundation is solid and well-tested — the gap is entirely "nothing to click," not "something insecure."
- `visible_to_client` currently gives staff a false sense that flipping it does something for the client — worth flagging as a user-facing affordance that doesn't yet function.

---

## 4. Lead Pipeline
**Status:** Partially built

**What's done:**
- `Lead` model + `LeadStatus`/`ClientSource` enums, `ExcludeConvertedLeadsScope` (global scope hiding converted leads by default, toggleable via a table filter).
- A **real kanban-style board exists**: `app/Filament/Admin/Pages/Pipeline.php` (nav: Leads → Pipeline, slug `leads/pipeline`) groups leads into status columns and renders them as a genuine card-per-column board (`resources/views/filament/admin/pages/pipeline.blade.php`) — color-coded columns, lead cards with phone/practice area/source/chase date, a "View client →" link for converted leads. Confirmed by direct file read.
- `NurtureSequence` model + relation manager. `ProcessLeadNurture` job implements BRIEF.txt's 4-stage logic exactly (ack email → 48h follow-up → 5-day SMS → 10-day final email + mark lost), each stage idempotent via `NurtureSequence` existence checks, and is actually scheduled (`routes/console.php`, `dailyAt('09:00')`, looped per tenant).
- Real "Convert to Client" action (`Lead::convertToClient()`) and "Mark as Lost" action, both functional and role-gated — not stubs.

**What's outstanding:**
- The kanban board is **read-only/display-only** — no drag-and-drop to change a lead's status from the board itself; status changes still happen via the resource table's actions or manual edit. If "kanban" was expected to mean interactive drag-to-change-stage, that part isn't built.
- SMS nurture step (stage 2) goes through `SmsService`, which is a stub (see cross-cutting note below) — no real SMS is sent.
- No dedicated test file for `ProcessLeadNurture` (only indirectly exercised).

**Notes/risks:**
- The automation logic (timing, dedup, cancel-on-convert/lost) is solid and correctly implemented — the gaps are UI interactivity and the SMS channel being fake, not the underlying logic.

---

## 5. Payments & Xero Integration
**Status:** Partially built

**What's done:**
- `PaymentPlan`/`Instalment`/`ChaseLog` models and enums match BRIEF.txt's schema. `PaymentPlanResource` with an editable `InstalmentsRelationManager` and a genuinely **read-only** `ChaseLogsRelationManager` (no header/record/toolbar actions registered).
- `ProcessPaymentChases` implements the exact tiered escalation from BRIEF.txt (3d/7d email, 14d/21d SMS, 21d → suspend matter + notify all directors), idempotent per instalment+channel+template via a `ChaseLog` existence check (a chase step is never re-sent), scheduled `dailyAt('08:00')` per tenant.
- `XeroWebhookController` verifies HMAC-SHA256 signatures correctly (`hash_equals`, constant-time) before dispatching `ProcessXeroPayment` for `INVOICE`/`UPDATE` events.
- `ProcessXeroPayment` marks the instalment paid, cancels pending chase logs, reactivates a suspended matter, and notifies directors. A previously confirmed production-crashing bug (eager-loading tenant-scoped relations before tenant context was set) is **fixed** in current code, with a regression test (`tests/Feature/Jobs/ProcessXeroPaymentTest.php`) that runs the job with no ambient tenant, matching a real queue worker.
- `Instalment::daysOverdue()` is a single shared source of truth for overdue math, unit-tested and correct (due-today is not yet overdue).

**What's outstanding:**
- **This is an inbound Xero webhook receiver only** — there is no Xero SDK, OAuth flow, or outbound API client anywhere in `composer.json`/`app/`. Nothing creates or syncs invoices to Xero. `xero_invoice_id` is a plain manually-typed text field (`InstalmentsRelationManager.php:40`) — a human creates the invoice in Xero directly and pastes the ID back in.
- `XERO_WEBHOOK_SECRET` is empty in `.env.example`; per `DEPLOY-ENV-CHECKLIST.md` this currently makes the endpoint 401 everything until a real secret is issued and set — **payments cannot auto-reconcile at all until this is configured.**
- SMS chase tiers (14d/21d) go through the same stub `SmsService` — no real SMS is sent.

**Notes/risks:**
- Treat "Xero Integration" as "Xero webhook receiver, one direction, requires a manual invoice-ID paste" — not two-way sync. Flag this explicitly, since the section name implies more.
- A mistyped `xero_invoice_id` means the webhook's lookup silently finds nothing and that payment is never reconciled, with no alerting on the failure.
- `app/Services/SmsService.php` is an explicit, documented stub ("logs to the application log for now — a real provider (Twilio/Vonage) will be wired in here before production"). This affects **every** SMS touchpoint in the app at once: payment chase tiers 3–4, lead nurture stage 2, and the portal invite SMS. If SMS is expected to work at go-live, this is a blocker, not a nice-to-have.

---

## 6. Time Tracking & Invoicing
**Status:** Partially built

**What's done:**
- `TimeEntry` model: duration tracking, `billable`/`billing_rate`/`billed_amount`, with `billed_amount` auto-computed on save (`duration_seconds/3600 × billing_rate`) — real, working billing math. Auto-fills `client_id`/`user_id` on create.
- `TimeEntryResource`: full CRUD, reachable via the admin user menu (deliberately kept off main nav) and embedded as a relation manager on the Matter's "Time" tab.
- Unusual but deliberate permission shape: `canAccess()` returns `true` for every role (commented as intentional, so accounts can see billing data), while edit is restricted to the record's own author for admin/solicitor (director can edit any), and delete is director-only.
- Audit logging is genuinely wired per BRIEF.txt's described pattern: `LogsActivity` + `HasReasonedActivityLog`, mandatory change-reason on edit only (`RequiresChangeReasonOnEdit` + `ChangeReasonField`), verified end-to-end by `tests/Feature/Filament/AuditLoggingTest.php`. TimeEntry is one of only **two** models with audit logging wired up at all (the other is PrecedentTemplate).

**What's outstanding:**
- **There is no invoicing.** `TimeEntry` has an `invoice_id` column but it's a bare, unvalidated string with no FK — there is no `Invoice` model, no invoices table, no service that aggregates time entries into an invoice document or total. "Invoicing" today means someone manually typing a reference string in after generating an invoice elsewhere.
- No bulk "mark as invoiced" action, no per-matter unbilled-time report beyond the relation manager's own hours/total summarizers.

**Notes/risks:**
- Because `invoice_id` isn't validated against any real invoice record, there's no way to prove which time entries were billed together or when — worth flagging given the SRA Accounts Rules gap in section 14.
- The everyone-can-`canAccess()` shape is the one resource that diverges from the "gate canAccess by role list" pattern used everywhere else — worth an explicit client sign-off that it's intended, not an oversight.

---

## 7. Documents
**Status:** Partially built

**What's done:**
- The Matter's Documents tab (`MatterDocumentsRelationManager`, in `MatterViewTabs`) — BRIEF.txt's "flat-tab implementation" — is real: typed file upload (PDF/Word/JPEG/PNG/XLSX) to a private disk, a `visible_to_client` toggle (director/admin only), download, delete.
- Real document generation: `DocumentGenerationService` uses PhpOffice\PhpWord's `TemplateProcessor` to merge matter/client/tenant-legal-entity fields into a `.docx` from a `PrecedentTemplate`, saving the result as both a `MatterDocument` and a `GeneratedDocument`. `PrecedentTemplateResource` manages the template library, with role gating (previously missing entirely — now fixed).
- Real AI summarization pipeline, end to end: PDF upload → `SummarizeMatterDocument` job → `PdfTextExtractionService` (Smalot PdfParser) → `DocumentSummaryService`, which calls a **local Ollama** LLM via the Prism PHP library using structured/schema-constrained output → result stored on `DocumentAiSummary` (`Pending`/`Completed`/`Failed`), with a retry action, 4s live polling in the UI, and graceful failure for unreachable Ollama or non-extractable (scanned) PDFs. `tests/Feature/Jobs/SummarizeMatterDocumentTest.php` mocks the HTTP calls and exercises success/failure paths.

**What's outstanding:**
- Per `DEPLOY-ENV-CHECKLIST.md`, the 6 seeded `PrecedentTemplate` records point at `.docx` files that **don't exist on disk yet** — "Generate Document" fails with a clean error until the firm supplies the real templates. This is a real go-live blocker, not a code gap.
- `DocumentGenerationService::resolveFields()` has several permanently-empty placeholder fields (`opponent_name`, `offer_amount`, `expert_name`, `expert_address`, `costs_to_date` — hardcoded `''`) — documents using those merge fields render blank.
- OCR for scanned/image-only PDFs is explicitly unsupported (a clean, documented failure, not a bug).
- No document versioning — re-uploading doesn't supersede anything.
- Storage is local disk only (`config/filesystems.php`'s `documents` disk is `local`) — an S3 disk is defined in config but not the active default; no off-box document storage is in use.

**Notes/risks:**
- The AI summarization feature has a **hard runtime dependency on a local-network Ollama server** reached over a WireGuard tunnel to a separate Mac Mini (`OLLAMA_URL=http://10.10.0.1:11434`) — not a hosted API. If that tunnel/machine is down, AI summaries fail gracefully but are dead. Worth surfacing explicitly — easy to assume "AI summary" means a cloud API call.
- `visible_to_client` is set in the admin UI as if it does something for a client, but currently has no effect anywhere (see Client Portal) — a false affordance until portal UI catches up.

---

## 8. Communications
**Status:** Not started (UI-wise)

**What's done:**
- `MatterMessage` model/migration exist and are displayed **read-only** in the Matter's "Communications" tab — a table of From/Message/Sent/Read, with an explicit placeholder when empty ("Client communications will appear here once the client portal is enabled for this matter").
- `CallNote` model exists and is genuinely populated — but only as a byproduct of the Retell voice integration (see section 9), not as a general communications feature.
- `client_requests` table exists in migrations only — no model, no UI anywhere. Fully dead schema.

**What's outstanding:**
- **No way for staff to send a message** — no create action, no write path anywhere in `app/Filament` for `MatterMessage`. It's a one-way read view of a table nothing currently writes to from the admin side.
- **`CallNote` has zero Filament UI** — no resource, no relation manager on Matter/Lead/Client. Call transcripts and AI summaries captured by the Retell webhook are written to the database but currently **invisible to staff** — there is no page to read them.
- No inbound/outbound email filing or parsing of any kind.

**Notes/risks:**
- This section is essentially unbuilt, and the codebase's own placeholder copy signals the authors know it. Flag clearly: the schema suggests a two-way staff↔client messaging feature was planned, and none of it has UI on either side.
- The Retell webhook's `needs_review`/`review_reason` flagging (ambiguous client match, multiple open matters, no match) has no surfaced queue, filter, or notification anywhere — since there's no `CallNote` UI, a flagged call is invisible until someone queries the database directly. Real operational risk: a flagged call could sit unreviewed indefinitely.

---

## 9. Voice AI (Retell)
**Status:** Partially built — inbound webhook ingestion only, well-built for what exists

**What's done:**
- `RetellWebhookController` is a real, carefully-built handler: HMAC-SHA256 signature verification (`v={timestamp},d={hmac}`, `hash_equals`, 5-minute replay window) before any processing.
- Every inbound event is persisted to `retell_call_logs` regardless of type; only `call_analyzed` events trigger business logic (`call_started`/`call_ended` are logged and no-op'd).
- Real matching logic on `call_analyzed`: (1) outbound calls carry `matter_id` via dynamic variables and attach directly; (2) inbound calls claiming to be an existing client are matched by normalized phone number, with single-open-matter auto-attach and multi-matter disambiguation by practice-area/case-category, flagging `needs_review` when ambiguous; (3) everything else creates a new `Lead` (source: Phone).
- Idempotent: re-delivery of the same `call_id`'s `call_analyzed` event is detected and business logic is skipped (the raw log row is still duplicated for audit purposes).
- Tenant-aware: resolves tenant from `agent_id` via `Tenant::findByRetellAgentId()`, wraps processing in `CurrentTenant::set()`/restore. A deliberate test proves a phone number shared across two tenants doesn't cross-contaminate.
- `config/services.php` has `retell.api_key`/`retell.webhook_secret` wired to env vars. This is the most heavily tested integration in the codebase (largest test file in the suite).

**What's outstanding:**
- **No `VoiceAgentService` interface or class exists anywhere** — searched explicitly, found nothing by that name or shape. There is no abstraction for outbound calling or Retell agent configuration; the webhook controller directly creates/updates models.
- **No outbound calling** — nothing in the app initiates a Retell call. This is reactive event ingestion only; the call itself happens entirely outside this app.
- **No admin UI to view captured calls** — no Filament resource or relation manager for `CallNote`/`RetellCallLog` anywhere (see section 8).

**Notes/risks:**
- Be precise with the client about scope: this is a real, well-tested webhook receiver that files calls into the CRM correctly — not a working voice agent the app can direct, and not currently visible to staff without a database query.
- `handleExistingClientCall` loads `Client::all()` into memory and filters in PHP for phone matching (`RetellWebhookController.php:203`) rather than a scoped query — fine at current scale, a scaling risk worth a note for later.

---

## 10. User Roles & Permissions
**Status:** Built (bespoke enum-based, not a package — matches BRIEF.txt's explicit "no FilamentShield" instruction)

**What's done:**
- `UserRole` enum (director/admin/solicitor/accounts) with simple, correct `hasRole()`/`hasAnyRole()` on `User`. No Laravel Policy classes exist anywhere (`app/Policies` doesn't exist) — all authorization is Filament's static `can*()` methods on each resource, as BRIEF.txt specifies.
- Verified per-resource matrix (read directly from each resource class):

  | Resource | canAccess | Edit/Delete note |
  |---|---|---|
  | Matters | director/admin/solicitor | locked → director only; else director/admin |
  | Clients | director/admin/solicitor | same locked pattern |
  | Leads | director/admin/solicitor | canDelete is **director only** (stricter than Matters/Clients) |
  | PaymentPlans | **director/accounts only** | locked → director only; else director/accounts |
  | TimeEntries | everyone (deliberate) | edit = own-record-only for admin/solicitor; delete = director only |
  | PrecedentTemplates | director/admin/solicitor | delete = director only (previously ungated entirely — fixed) |
  | AuditLog | director only | fully read-only for everyone, no create/edit/delete |

- `director_only` is enforced at the **query level** on Matters/Clients/Leads (`getEloquentQuery`), not just the view gate — a non-director can't resolve a director-only record by direct URL, confirmed by reading the query-scoping code.
- `SCOPE_SOLICITORS` is real and consumed (not a dead config value) — filters Matters to `assigned_user_id` for solicitors when enabled.
- `User` correctly implements `FilamentUser` (previously missing entirely — a confirmed "every staff login 403s in production" bug, now fixed).

**What's outstanding:**
- `SCOPE_SOLICITORS` only scopes Matters — TimeEntries still lets any solicitor see all time entries regardless of the flag (`canAccess()` is unconditionally `true`). Worth a deliberate decision on whether that's intended.
- Solicitors' access to Finance data on a Matter is blocked by a UI-level `visible()` check on the Finance tab itself, not a resource-level gate — a different enforcement mechanism than PaymentPlanResource's own `canAccess`. Worth confirming this is the intended enforcement point, since it's inconsistent with how other role restrictions are implemented.

**Notes/risks:**
- Enforcement is 100% dependent on every resource remembering to declare `can*()` methods — there's no central policy layer catching an omission. One resource (PrecedentTemplates) previously had no gating at all; it's fixed now, but the pattern means a newly added resource could ship the same gap unless someone remembers.

---

## 11. Multi-tenancy
**Status:** Built — functioning now, not just scaffolded (BRIEF.txt's "scaffold for later" framing is out of date)

**What's done:**
- Real Filament-native multi-tenancy: `AdminPanelProvider` calls `->tenant(Tenant::class, slugAttribute: 'slug')`, resolving tenant from the URL slug via Filament's own middleware.
- `Tenant` model supports a two-level hierarchy (`parentTenant()`/`legalEntity()`) for trading-style brands under one regulated legal entity, with real SRA-relevant fields (`sra_number`, `company_number`, `legal_entity_name`) — well beyond BRIEF.txt's original scope.
- `TenantScope` is a **fail-closed** global scope: with no resolved tenant, it forces zero rows rather than returning everything — a deliberate, documented design choice, not an accident.
- `CurrentTenant` gives one canonical resolution order app-wide (explicit `set()` wins, else `Filament::getTenant()`), safe to call from console/jobs/webhooks. `BelongsToTenant` applies `TenantScope` automatically and auto-derives `tenant_id` on create (inherited from a parent relation, e.g. `CallNote` from its matter, or from `CurrentTenant`). 17 of 19 models use it; `User` and `Tenant` are correctly excluded.
- `ReservedSlugs` prevents a tenant slug from colliding with a real route prefix (`admin`, `login`, `retell`, `webhooks`, etc.), enforced at both save-time and route-compile-time from one shared list, with a test guarding the two enforcement points against drift.
- Batch jobs and webhook handlers (`ProcessPaymentChases`, `ProcessLeadNurture`, `RetellWebhookController`, `ProcessXeroPayment`) all correctly set/restore tenant context around per-tenant work.
- Real regression tests for two confirmed production incidents: a bug where `CurrentTenant::id()` bypassed the Filament fallback (every tenant's panel showed zero rows), and a routing collision where the portal's catch-all route outranked `/admin/{tenant}` and routed staff into the client-portal guard ("took down staff login" per the test's own docblock).
- Isolation is proven at the **query level** for Clients (`ClientResourceTenantScopingTest`, using Filament's real tenant-set flow) and at the **write level** for the Retell webhook (a deliberate shared-phone-number-across-two-tenants test).

**What's outstanding:**
- No Tenant CRUD UI exists — the `Tenant` model's own docblock states tenants come from seeders/tinker only. Fine for a single-firm deploy; would need building before onboarding a second firm through the UI.

**Notes/risks:**
- The fail-closed scoping is a genuinely strong compliance property (a missing tenant context produces an empty result, not a leak) — worth highlighting positively.
- Isolation is architecturally identical across every `BelongsToTenant` model (same scope, same mechanism) but is only **independently test-proven** for Client (list-level) and the Retell webhook path (write-level) — not for Leads, Matters, PaymentPlans, etc. individually. Treat "TenantScope is applied everywhere" (true) as distinct from "isolation is test-proven for every resource" (only proven for two paths).

---

## 12. Backup & Disaster Recovery
**Status:** Not started

**What's done:**
- Nothing. `spatie/laravel-backup` (or any backup package) is not in `composer.json`/`composer.lock`. No `config/backup.php`. No backup-related Artisan command (the only custom command is `AiTestCommand`). `routes/console.php` schedules only the two business jobs — no backup task. `DEPLOY-ENV-CHECKLIST.md` and `README.md` contain no backup-related content at all.

**What's outstanding:**
- Everything: install/configure a backup solution (DB dump + `matter_documents`/`storage/app` files), an offsite destination, a schedule, failure monitoring/alerting, a defined retention policy, and a documented, tested restore procedure.

**Notes/risks:**
- This is a hard, total gap with no partial credit. For a law firm about to trust the system with live case files and payment records, this should be a pre-go-live blocker, not a "later" polish item — flag it as such in the tracker.
- Compounds with the Archive System gap below: hard deletes + no backups means a permitted delete today is unrecoverable by any means.

---

## 13. Archive System
**Status:** Not started

**What's done:**
- Nothing. No `archived_at`/`archived_by` columns anywhere in the 44 migrations. No archive-related trait, scope, model, or Filament action anywhere in `app/`. No model uses Laravel's `SoftDeletes` trait — every delete permitted by `canDelete()` is a permanent hard delete.

**What's outstanding:**
- Decide on and implement an archive pattern — either `archived_at`/`archived_by` + a default-excluding scope + a restore action (the codebase already has a working precedent for this shape in `ExcludeConvertedLeadsScope`, so the pattern to copy exists), or Laravel `SoftDeletes` if that fits the actual requirement better — for whichever models need it (most likely Matter, Client, Lead: closed/lost/converted records a firm wants to retain but hide from active lists).
- Build the restore flow (a UI action at minimum).

**Notes/risks:**
- A closed `Matter` (`status = closed`) is just a status value today, not an archived record — don't conflate the two when scoping this work.
- Combined with no backups (section 12) and no soft-deletes, any permitted delete today is permanently unrecoverable through any mechanism in the app.

---

## 14. Compliance & Security
**Status:** Partially built — access control and tenant isolation are genuinely strong; several regulatory-relevant pillars are absent. This is the highest-priority section; read it in full.

**What's done:**
- **Access control granularity:** Verified above (section 10) — the role matrix is real, mostly consistent, and enforced via Filament's own framework hooks. Row-level filtering (not just view-gating) is applied to director_only content on Matters/Clients/Leads.
- **Audit logging mechanism** (where applied) is well-designed: `spatie/laravel-activitylog`, `HasReasonedActivityLog` (mandatory reason on edits, none required on create/delete — matches BRIEF.txt), `RequiresChangeReasonOnEdit` on edit pages, tenant-stamped log rows (enforced by a dedicated regression test, `ActivityLogTenantStampingTest`, that fails the build if a `LogsActivity` model doesn't also stamp `tenant_id`), a director-only browsable Audit Log resource.
- **In transit:** `AppServiceProvider::preventDebugModeOnNonLocalUrl()` is real and matches BRIEF.txt's description — forces debug off on any non-local-looking `APP_URL`. The app forces the URL generator to `https://` when `APP_URL` starts with `https`.
- **Password hashing:** bcrypt (`BCRYPT_ROUNDS=12`) via Laravel's standard `hashed` cast, on both `User` and `Client`.
- **Multi-tenant data isolation** (section 11) is real, fail-closed, and tested — directly relevant to compliance since it's what prevents cross-firm data leakage in the multi-tenant architecture.

**What's outstanding — stated plainly:**
- **Audit logging covers only 2 of ~17 tenant-scoped models: `TimeEntry` and `PrecedentTemplate`** (confirmed by direct grep — no other model uses `LogsActivity`). `Matter`, `Client`, `Lead`, `PaymentPlan`, `Instalment`, `ChaseLog`, `MatterDocument` have **no audit trail at all** — no record of who edited a client's details, changed a matter's status, or touched a payment plan, and when. For a law firm, these are exactly the records an SRA inspection or a client complaint would need traceability on. The fix is proven and cheap to extend (two traits + one method per model, following the existing TimeEntry/PrecedentTemplate pattern) — this is the single highest-value, lowest-effort item in this whole audit.
- **No encryption at rest at the application layer** (confirmed by grep — no `encrypted` Eloquent casts, no `Crypt::` usage anywhere in `app/Models`). Client/matter notes, addresses, call transcripts, and messages are stored as plain text in MySQL; the documents disk has no app-level encryption configuration. May be handled at infrastructure/disk level, but nothing in the app enforces or assumes that.
- **No enforced HTTPS redirect at the application layer** — `APP_URL`-based URL generation forces `https://` links when configured for it, but there's no middleware redirecting a plain HTTP request; this is presumably intended to be a reverse-proxy responsibility, but nothing in the Laravel app itself guarantees it.
- **`SESSION_SECURE_COOKIE` is entirely absent from `.env.example`** (confirmed by direct read) — it isn't set to `false`, it simply isn't there, meaning whoever configures production must know to add it. `DEPLOY-ENV-CHECKLIST.md` flags this and recommends `true` for production, but nothing enforces it in code. `SESSION_ENCRYPT=false` is also the shipped default.
- **No MFA/2FA anywhere** (confirmed by grep across `composer.json` — no Fortify 2FA, no `pragmarx/google2fa`, nothing equivalent). Login is email + password only, for both staff and clients.
- **No data retention or deletion mechanism.** No soft deletes anywhere (all deletes are permanent), no right-to-erasure/GDPR export-or-purge command, no retention-policy job for client data. The only retention logic in the codebase is `laravel-activitylog`'s own 365-day log cleanup, which governs the audit log itself, not client records. A client data-deletion request today requires a manual database operation.
- **No SRA Accounts Rules-relevant separation exists.** `payment_plans`/`instalments` is a single, undifferentiated ledger — there is no distinction anywhere in the schema or code between client account money and office/business money, and nothing resembling trust accounting. This needs real design work, not a small addition, before the firm could rely on Kase for anything SRA Accounts Rules-relevant once real invoicing goes live. State this plainly to the client — it's a genuine regulatory gap, not a nice-to-have.

**Notes/risks:**
- The pattern across this codebase is consistent: where a compliance mechanism exists, it's well-built and tested (tenant isolation, the audit-log mechanism itself, role gating). The risk in this section is **coverage**, not **quality** — extend what already works to the records that currently lack it, starting with audit logging on Matter/Client/PaymentPlan/Instalment.
- No Laravel Policy classes exist at all (`app/Policies` doesn't exist) — this is a deliberate architectural choice matching BRIEF.txt's explicit instruction, not an oversight, but it does mean there's no central authorization layer independent of each Filament resource remembering its own gates correctly.

---

## 15. Dev/Deploy Hygiene
**Status:** Partially built

**What's done:**
- `DatabaseSeeder` is production-safe: creates exactly one director user with a random 24-char password printed once to console (never stored/logged), plus `PrecedentTemplateSeeder` — no fake demo client/matter/lead data that could leak into a production run.
- `preventDebugModeOnNonLocalUrl()` (`AppServiceProvider.php`) is real and matches BRIEF.txt's description exactly — forces debug off outside local-style hostnames.
- Current `.env`/`.env.example` is correctly configured for local dev (`APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost` or the Laragon `*.test` domain — a valid local pattern per the safety rule).
- `DEPLOY-ENV-CHECKLIST.md` is genuinely thorough and specific (not boilerplate) — it correctly catalogs every env var that must change for production, and honestly flags current blockers: empty `MAIL_MAILER`/`XERO_WEBHOOK_SECRET`/`RETELL_WEBHOOK_SECRET` in dev, missing precedent-template `.docx` files, the requirement for a running queue worker (else AI summaries hang at "Processing…" forever), and a from-zero migration/seed verification that was actually performed.

**What's outstanding:**
- **No anonymizing/data-scrubbing Artisan command exists anywhere** — if a production DB snapshot is ever pulled for local debugging, there's no tooling to scrub client PII first.
- **No Cloudflare Tunnel setup or documentation found anywhere** in the repo (config, `.env.example`, `README.md`, `DEPLOY-ENV-CHECKLIST.md` — all checked). The actual tunnel in use is a **WireGuard tunnel to a Mac Mini**, for reaching the self-hosted Ollama instance — if Cloudflare Tunnel was the expected architecture, that's not what's actually configured.
- `README.md` is the unmodified default Laravel README — no project-specific setup/deploy instructions live there; everything useful is in `DEPLOY-ENV-CHECKLIST.md` instead.
- Per the checklist's own blocker list: mail credentials (M365 SMTP), the Xero webhook secret, and the 6 precedent-template `.docx` files are all still outstanding for a real production deploy.

**Notes/risks:**
- The team is clearly tracking deploy readiness carefully (the checklist's existence and specificity is evidence of that) — treat its "BLOCKER" rows as a direct go-live punch list.

---

## 16. AI Layer (Phase 3 / Quill)
**Status:** Partially built — but what exists is real, working, tested code, not scaffolding

**What's done:**
- `QuillChat` (Livewire) is a genuinely functional matter-scoped AI chat assistant, embedded both in each Matter's "Ask Quill" tab and as a floating `QuillLauncher` present app-wide. It builds context via `QuillPromptContextBuilder`, calls a self-hosted **Ollama** model (`qwen3:8b`) through the **Prism** PHP library, persists `QuillConversation`/`QuillMessage` (role/status enums) across multi-turn history, and fails gracefully (5s connect timeout, caught `Throwable`, message marked `Failed` rather than hanging).
- `QuillPromptContextBuilder` assembles real context — matter reference, client, practice area, status, court date, plus completed document AI summaries — capped at 12,000 characters. Deliberately not RAG (no chunking/embeddings) — a documented, in-scope design decision, not a missing feature; Quill only ever sees what's explicitly assembled.
- `DocumentSummaryService` (section 7) uses the same Ollama/Prism stack for document summarization — also real, also tested.
- `ai:test` Artisan command (`AiTestCommand`) is a working connectivity smoke-test that sends a real prompt through the full Laravel → Prism → tunnel → Ollama chain and prints the response.
- `config/prism.php` is fully configured for Ollama; other providers (OpenAI, Anthropic, etc.) are present in config but with empty API keys — dormant capability, not active integrations.
- Because inference runs against a self-hosted model over a private tunnel rather than a third-party API, no client/case data leaves the firm's own infrastructure for AI features — a genuine privacy positive worth highlighting to a legal client.

**What's outstanding:**
- The entire AI layer depends on the WireGuard tunnel to the Mac Mini being up; per `DEPLOY-ENV-CHECKLIST.md`, if unreachable, "AI features fail gracefully (verified) but are dead" — there's no fallback provider or queued retry-when-back-online behavior.
- No retrieval/RAG, by design — worth confirming with the client this matches their expectations of what "AI layer" means (context-window-only, not a knowledge-base search).
- `QuillExpression` enum (mascot expression states) exists but wasn't confirmed wired into an actual UI state — worth a quick follow-up if the mascot's visual states matter for a demo.

**Notes/risks:**
- With `QUEUE_CONNECTION=database`, document AI summaries will sit at "Processing…" indefinitely if no queue worker is running in production — a real operational dependency, not just a code concern, and already flagged in the deploy checklist.
- This section is meaningfully more built than "fully conceptual" — it's real, tested code with sensible failure handling. The risk here is entirely operational (keep the tunnel/worker up), not that the feature is vaporware.

---

## Cross-cutting items worth their own tracker rows

- **`SmsService` is a stub app-wide.** It logs instead of sending, and this silently affects payment chase tiers 3–4, lead nurture stage 2, and portal invite SMS simultaneously. One fix (wire a real provider) unblocks three features at once.
- **Audit-log coverage is 2 of ~17 models.** Highest-value, lowest-effort compliance fix identified in this audit — the pattern to extend already exists and works.
- **No backup/DR and no archive/soft-delete** compound each other: a permitted delete today is permanent and unrecoverable by any means in the app.
- **Client portal UI is far behind its schema** — almost everything a client would expect to see (documents, payments, messages) exists in the database but not in the portal.
- **`matter_messages` and `client_requests` are dead schema** — tables and (for messages) a read-only display exist, but nothing writes to them from either the staff or client side.
- **Xero integration is inbound-only** and depends on a human manually pasting an invoice ID — not the two-way sync the section name might imply.
- **No SRA client-money/office-money separation** — flagged plainly as a real regulatory gap for a solicitors' firm, not a polish item, once real invoicing goes live.
