<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Support\Environment;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Section 15's missing piece. Purpose: a real production database snapshot
 * pulled down to a laptop for local development must never leave real
 * client PII sitting there unprotected — this scrubs it in place.
 *
 * Deliberately raw DB::table() updates for almost everything (fast, and
 * bypasses every model's booted() hooks — Client/DocumentAiSummary/CallNote
 * etc. all have observers that send notifications or write activity log
 * entries on change, none of which should fire while anonymizing). The two
 * exceptions — Client and User — go through Eloquent (forceFill()
 * ->saveQuietly(), still event-free) because ni_number and the MFA secret
 * columns use an `encrypted` cast: writing plaintext into them via a raw
 * UPDATE would leave the column permanently undecryptable garbage instead
 * of a working fake value.
 *
 * Everything runs inside one DB transaction: a scrub that dies halfway
 * through, leaving some rows real and some fake, is worse than either not
 * running at all or being safely rolled back.
 */
class AnonymizeDatabaseCommand extends Command
{
    protected $signature = 'kase:anonymize-database {--force : Skip the interactive confirmation prompt}';

    protected $description = 'Irreversibly scrub PII and auth secrets from the CURRENT database — only for a local copy of a production snapshot, never production itself';

    /**
     * Every local account (staff and client portal) ends up with this
     * password after the scrub — one well-known value rather than a
     * per-user random one nobody could ever log in with locally.
     */
    private const DEV_PASSWORD = 'password';

    private Generator $faker;

    public function handle(): int
    {
        if (! $this->isSafeEnvironment()) {
            $this->components->error(
                'Refusing to run: APP_ENV/APP_URL do not look like a local environment. '
                .'This command permanently destroys real data and must never run against production.',
            );

            return self::FAILURE;
        }

        if (! class_exists(Factory::class)) {
            $this->components->error('fakerphp/faker is not installed (it is a require-dev package) — run `composer install` without --no-dev first.');

            return self::FAILURE;
        }

        $this->components->warn('This PERMANENTLY overwrites client/staff personal data, call transcripts, message content, AI-extracted fields, and auth secrets in the CURRENT database with fake equivalents.');
        $this->components->warn('It does not touch actual uploaded document files on disk — see the summary printed at the end.');

        if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
            $this->components->info('Aborted — no changes made.');

            return self::SUCCESS;
        }

        $this->faker = Factory::create('en_GB');

        $summary = [];

        DB::transaction(function () use (&$summary): void {
            $this->components->task('Clients', function () use (&$summary) {
                $summary['clients'] = $this->anonymizeClients();
            });
            $this->components->task('Leads', function () use (&$summary) {
                $summary['leads'] = $this->anonymizeLeads();
            });
            $this->components->task('Matters', function () use (&$summary) {
                $summary['matters'] = $this->anonymizeMatters();
            });
            $this->components->task('Call notes (transcripts/summaries)', function () use (&$summary) {
                $summary['call_notes'] = $this->anonymizeCallNotes();
            });
            $this->components->task('Document AI summaries', function () use (&$summary) {
                $summary['document_ai_summaries'] = $this->anonymizeDocumentAiSummaries();
            });
            $this->components->task('Matter messages', function () use (&$summary) {
                $summary['matter_messages'] = $this->anonymizeMatterMessages();
            });
            $this->components->task('Matter document filenames', function () use (&$summary) {
                $summary['matter_documents'] = $this->anonymizeMatterDocumentFilenames();
            });
            $this->components->task('Generated document filenames', function () use (&$summary) {
                $summary['generated_documents'] = $this->anonymizeGeneratedDocumentFilenames();
            });
            $this->components->task('Client requests', function () use (&$summary) {
                $summary['client_requests'] = $this->anonymizeClientRequests();
            });
            $this->components->task('Time entry descriptions', function () use (&$summary) {
                $summary['time_entries'] = $this->anonymizeTimeEntries();
            });
            $this->components->task('Quill AI conversation content', function () use (&$summary) {
                $summary['quill_messages'] = $this->anonymizeQuillMessages();
            });
            $this->components->task('Retell call log payloads', function () use (&$summary) {
                $summary['retell_call_logs'] = $this->redactRetellCallLogs();
            });
            $this->components->task('Impersonation session reasons', function () use (&$summary) {
                $summary['impersonation_sessions'] = $this->anonymizeImpersonationSessions();
            });
            $this->components->task('Invoices (external references only)', function () use (&$summary) {
                $summary['invoices'] = $this->anonymizeInvoiceReferences();
            });
            $this->components->task('Accounting connections (OAuth tokens wiped)', function () use (&$summary) {
                $summary['accounting_connections'] = $this->wipeAccountingConnections();
            });
            $this->components->task('Accounting reconciliation issue payloads', function () use (&$summary) {
                $summary['accounting_reconciliation_issues'] = $this->redactAccountingReconciliationIssues();
            });
            $this->components->task('Tenant (firm) identity fields', function () use (&$summary) {
                $summary['tenants'] = $this->anonymizeTenants();
            });
            $this->components->task('Scheduled task ping URLs', function () use (&$summary) {
                $summary['monitored_scheduled_tasks'] = $this->wipeScheduledTaskPingUrls();
            });
            $this->components->task('Users (names/emails scrubbed, auth secrets wiped)', function () use (&$summary) {
                $summary['users'] = $this->resetUsers();
            });
            $this->components->task('Ephemeral tables cleared (sessions, notifications, activity log, queue)', function () use (&$summary) {
                $summary['ephemeral rows deleted'] = $this->clearEphemeralTables();
            });
        });

        $this->newLine();
        $this->components->info('Database anonymized. Rows affected:');
        foreach ($summary as $label => $count) {
            $this->line("  {$label}: {$count}");
        }

        $this->newLine();
        $this->components->warn('Every user and client password is now: '.self::DEV_PASSWORD);
        $this->components->warn('MFA (TOTP) secrets and recovery codes were wiped — affected users must re-enroll.');
        $this->components->warn('Xero OAuth tokens were wiped — accounting connections must be re-authorized (they will fail closed until then, not silently use stale creds).');
        $this->components->warn('Financial figures (invoice/instalment/payment-plan amounts, billing rates, time-entry duration) were left UNCHANGED — my read is that a bare number isn\'t sensitive once the client/matter it belongs to is already anonymized, and preserving them keeps the data realistic for testing billing/reporting features. Say if you want these randomized too.');
        $this->components->warn('Actual uploaded document FILE CONTENTS on the storage disk are NOT touched — this command only scrubs database rows. If real files were copied down alongside the database, they must be excluded or scrubbed separately; only their filenames (a database column) were anonymized here.');

        return self::SUCCESS;
    }

    private function isSafeEnvironment(): bool
    {
        return app()->environment('local') && Environment::isLocalUrl();
    }

    private function anonymizeClients(): int
    {
        $count = 0;

        Client::allTenants()->chunkById(200, function ($clients) use (&$count): void {
            foreach ($clients as $client) {
                $firstName = $this->faker->firstName();
                $lastName = $this->faker->lastName();

                $client->forceFill([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $this->fakeEmail($client->id),
                    'phone' => $client->phone !== null ? $this->faker->numerify('07#########') : null,
                    'address' => $client->address !== null ? $this->faker->address() : null,
                    'date_of_birth' => $client->date_of_birth !== null ? $this->faker->dateTimeBetween('-90 years', '-18 years')->format('Y-m-d') : null,
                    'ni_number' => $client->ni_number !== null ? $this->fakeNiNumber() : null,
                    'notes' => $client->notes !== null ? $this->faker->paragraph() : null,
                    'provider_contact_id' => null,
                    'portal_token' => null,
                    'password' => self::DEV_PASSWORD,
                ])->saveQuietly();

                $count++;
            }
        });

        return $count;
    }

    private function anonymizeLeads(): int
    {
        $count = 0;

        DB::table('leads')->orderBy('id')->chunkById(200, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                DB::table('leads')->where('id', $row->id)->update([
                    'first_name' => $this->faker->firstName(),
                    'last_name' => $this->faker->lastName(),
                    'email' => $row->email !== null && $row->email !== '' ? $this->fakeEmail($row->id) : $row->email,
                    'telephone' => $row->telephone !== null && $row->telephone !== '' ? $this->faker->numerify('01#########') : $row->telephone,
                    'mobile' => $row->mobile !== null && $row->mobile !== '' ? $this->faker->numerify('07#########') : $row->mobile,
                    'message' => $this->faker->paragraph(),
                    'gclid' => $row->gclid !== null ? Str::random(24) : null,
                ]);
                $count++;
            }
        });

        return $count;
    }

    private function anonymizeMatters(): int
    {
        $count = 0;

        DB::table('matters')->orderBy('id')->chunkById(200, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                DB::table('matters')->where('id', $row->id)->update([
                    'notes' => $row->notes !== null ? $this->faker->paragraphs(rand(1, 3), true) : null,
                    'title' => $row->title !== null ? $this->faker->sentence(4) : null,
                    'sentence' => $row->sentence !== null ? $this->faker->paragraph() : null,
                    'offence_location' => $row->offence_location !== null ? $this->faker->streetAddress() : null,
                ]);
                $count++;
            }
        });

        return $count;
    }

    private function anonymizeCallNotes(): int
    {
        $count = 0;

        DB::table('call_notes')->orderBy('id')->chunkById(200, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                DB::table('call_notes')->where('id', $row->id)->update([
                    'transcript' => $row->transcript !== null ? $this->faker->paragraphs(rand(3, 6), true) : null,
                    'summary' => $row->summary !== null ? $this->faker->paragraph() : null,
                    'review_reason' => $row->review_reason !== null ? $this->faker->sentence() : null,
                ]);
                $count++;
            }
        });

        return $count;
    }

    /**
     * key_facts/extracted_fields/field_comparisons are JSON blobs that embed
     * real extracted PII (see DocumentSummaryService/
     * DocumentFieldComparisonService) — each array is rebuilt with the same
     * shape (same keys, same element counts) but fake values, so a blank
     * field stays blank and an array of 3 names stays an array of 3 names.
     */
    private function anonymizeDocumentAiSummaries(): int
    {
        $count = 0;

        DB::table('document_ai_summaries')->orderBy('id')->chunkById(200, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                DB::table('document_ai_summaries')->where('id', $row->id)->update([
                    'summary' => $row->summary !== null ? $this->faker->paragraph() : null,
                    'key_facts' => $this->fakeKeyFacts($row->key_facts),
                    'extracted_fields' => $this->fakeExtractedFields($row->extracted_fields),
                    'field_comparisons' => $this->fakeFieldComparisons($row->field_comparisons),
                    'review_reason' => $row->review_reason !== null ? $this->faker->sentence() : null,
                ]);
                $count++;
            }
        });

        return $count;
    }

    private function fakeKeyFacts(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return $json;
        }

        $decoded['names'] = array_map(fn () => $this->faker->name(), $decoded['names'] ?? []);
        $decoded['dates'] = array_map(fn () => $this->faker->date('d/m/Y'), $decoded['dates'] ?? []);
        $decoded['monetary_figures'] = array_map(fn () => '£'.$this->faker->numberBetween(50, 10000), $decoded['monetary_figures'] ?? []);

        return json_encode($decoded);
    }

    private function fakeExtractedFields(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return $json;
        }

        foreach ($decoded as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $decoded[$key] = $this->fakeValueForExtractedField($key);
        }

        return json_encode($decoded);
    }

    private function fakeFieldComparisons(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return $json;
        }

        foreach ($decoded as $key => $comparison) {
            if (! is_array($comparison)) {
                continue;
            }

            $fake = $this->fakeValueForExtractedField($key);

            if (array_key_exists('extracted', $comparison)) {
                $comparison['extracted'] = $fake;
            }

            if (array_key_exists('crm', $comparison) && $comparison['crm'] !== null && $comparison['crm'] !== '') {
                $comparison['crm'] = $fake;
            }

            $decoded[$key] = $comparison;
        }

        return json_encode($decoded);
    }

    private function fakeValueForExtractedField(string $key): string
    {
        return match ($key) {
            'ni_number' => $this->fakeNiNumber(),
            'date_of_birth' => $this->faker->date('d/m/Y'),
            'phone' => $this->faker->numerify('07#########'),
            'address' => $this->faker->address(),
            'court_reference' => strtoupper($this->faker->bothify('case-####-???')),
            default => $this->faker->word(),
        };
    }

    private function anonymizeMatterMessages(): int
    {
        return $this->rewriteRowByRow('matter_messages', fn ($row) => [
            'body' => $this->faker->paragraph(),
        ]);
    }

    private function anonymizeMatterDocumentFilenames(): int
    {
        return $this->rewriteRowByRow('matter_documents', fn ($row) => [
            'filename' => $this->fakeFilename($row->filename),
        ]);
    }

    private function anonymizeGeneratedDocumentFilenames(): int
    {
        return $this->rewriteRowByRow('generated_documents', fn ($row) => [
            'filename' => $this->fakeFilename($row->filename),
        ]);
    }

    private function anonymizeClientRequests(): int
    {
        return $this->rewriteRowByRow('client_requests', fn ($row) => [
            'description' => $row->description !== null ? $this->faker->paragraph() : null,
        ]);
    }

    private function anonymizeTimeEntries(): int
    {
        return $this->rewriteRowByRow('time_entries', fn ($row) => [
            'description' => $row->description !== null ? $this->faker->sentence() : null,
        ]);
    }

    private function anonymizeQuillMessages(): int
    {
        return $this->rewriteRowByRow('quill_messages', fn ($row) => [
            'content' => $this->faker->paragraph(),
        ]);
    }

    private function redactRetellCallLogs(): int
    {
        return $this->rewriteRowByRow('retell_call_logs', fn ($row) => [
            'raw_payload' => json_encode(['redacted' => true, 'note' => 'Original webhook payload removed by kase:anonymize-database.']),
        ]);
    }

    private function anonymizeImpersonationSessions(): int
    {
        return $this->rewriteRowByRow('impersonation_sessions', fn ($row) => [
            'reason' => $this->faker->sentence(),
        ]);
    }

    /**
     * Amounts are deliberately left alone (see the summary warning) —
     * only the external Xero-side reference numbers, which could be used
     * to cross-reference a real Xero account, are randomized.
     */
    private function anonymizeInvoiceReferences(): int
    {
        return $this->rewriteRowByRow('invoices', fn ($row) => [
            'provider_invoice_id' => $row->provider_invoice_id !== null ? Str::random(16) : null,
            'provider_invoice_number' => $row->provider_invoice_number !== null ? strtoupper(Str::random(10)) : null,
        ]);
    }

    /**
     * Access/refresh tokens are auth secrets, not PII — wiped outright
     * rather than replaced with a fake-but-plausible token, since no fake
     * token could ever work anyway and leaving one in place risks someone
     * assuming a scrubbed connection is still live.
     */
    private function wipeAccountingConnections(): int
    {
        return $this->rewriteRowByRow('accounting_connections', fn ($row) => [
            'access_token' => null,
            'refresh_token' => null,
            'external_org_id' => null,
            'account_code' => null,
        ]);
    }

    private function redactAccountingReconciliationIssues(): int
    {
        return $this->rewriteRowByRow('accounting_reconciliation_issues', fn ($row) => [
            'external_invoice_id' => $row->external_invoice_id !== null ? Str::random(16) : null,
            'payload' => $row->payload !== null ? json_encode(['redacted' => true]) : null,
        ]);
    }

    private function anonymizeTenants(): int
    {
        return $this->rewriteRowByRow('tenants', fn ($row) => [
            'name' => $this->faker->company(),
            'tagline' => $row->tagline !== null ? $this->faker->catchPhrase() : null,
            'logo_path' => null,
            'legal_entity_name' => $row->legal_entity_name !== null ? $this->faker->company().' Ltd' : null,
            'company_number' => $row->company_number !== null ? (string) $this->faker->numberBetween(10000000, 99999999) : null,
            'sra_number' => $row->sra_number !== null ? (string) $this->faker->numberBetween(100000, 999999) : null,
            'firm_address' => $row->firm_address !== null ? $this->faker->address() : null,
            'firm_phone' => $row->firm_phone !== null ? $this->faker->numerify('01#########') : null,
            'firm_email' => $row->firm_email !== null ? $this->fakeEmail($row->id, 'firm') : null,
        ]);
    }

    private function wipeScheduledTaskPingUrls(): int
    {
        return $this->rewriteRowByRow('monitored_scheduled_tasks', fn ($row) => [
            'ping_url' => null,
        ]);
    }

    /**
     * Staff/portal identity — names and emails are still real personal data
     * (GDPR doesn't stop applying just because someone is an employee
     * rather than a client), scrubbed the same way. Passwords, remember
     * tokens, and MFA secrets/recovery codes are wiped outright, per the
     * same "never let real credentials work on a local copy" rule as the
     * accounting OAuth tokens.
     */
    private function resetUsers(): int
    {
        $count = 0;

        User::query()->chunkById(200, function ($users) use (&$count): void {
            foreach ($users as $user) {
                $user->forceFill([
                    'name' => $this->faker->name(),
                    'email' => $this->fakeEmail($user->id, 'staff'),
                    'password' => self::DEV_PASSWORD,
                    'remember_token' => null,
                    'app_authentication_secret' => null,
                    'app_authentication_recovery_codes' => null,
                ])->saveQuietly();

                $count++;
            }
        });

        return $count;
    }

    /**
     * Deleted outright rather than scrubbed field-by-field: sessions,
     * notifications, activity log entries (which embed real old/new
     * attribute values for every logged model — see LogsActivity usage
     * throughout app/Models), password reset tokens, and queued/failed job
     * payloads are all ephemeral/operational data, not business records
     * worth preserving the shape of for realistic local testing.
     */
    private function clearEphemeralTables(): int
    {
        $tables = ['sessions', 'password_reset_tokens', 'notifications', 'activity_log', 'jobs', 'failed_jobs', 'job_batches'];
        $total = 0;

        foreach ($tables as $table) {
            $total += DB::table($table)->delete();
        }

        return $total;
    }

    /**
     * Shared row-by-row rewrite helper for the many tables that need no
     * special Eloquent/encryption handling — fetches by id, applies the
     * given fake-value map, and writes it straight back.
     *
     * @param  \Closure(object): array<string, mixed>  $values
     */
    private function rewriteRowByRow(string $table, \Closure $values): int
    {
        $count = 0;

        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $values, &$count): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update($values($row));
                $count++;
            }
        });

        return $count;
    }

    private function fakeNiNumber(): string
    {
        // Approximates real HMRC NI-number rules (excludes D/F/I/Q/U/V as
        // the first letter, D/F/I/O/Q/U/V as the second) closely enough to
        // look genuine without needing to be spec-perfect — this only ever
        // needs to not collide with a real number, not pass HMRC validation.
        return $this->faker->regexify('[A-CEGHJ-PRSTW-Z]{1}[A-CEGHJ-NPRSTW-Z]{1}[0-9]{6}[A-D]');
    }

    private function fakeEmail(int|string $id, string $prefix = 'person'): string
    {
        return "{$prefix}-{$id}@example-anon.test";
    }

    private function fakeFilename(?string $original): ?string
    {
        if ($original === null) {
            return null;
        }

        $extension = pathinfo($original, PATHINFO_EXTENSION) ?: 'pdf';

        return Str::slug($this->faker->words(3, true)).'.'.$extension;
    }
}
