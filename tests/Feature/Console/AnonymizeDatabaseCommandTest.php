<?php

namespace Tests\Feature\Console;

use App\Enums\AccountingProviderKey;
use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use App\Enums\MatterStatus;
use App\Models\AccountingConnection;
use App\Models\CallNote;
use App\Models\Client;
use App\Models\DocumentAiSummary;
use App\Models\Lead;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\MatterMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Section 15 — verifies the anonymizer actually scrubs real content rather
 * than just "ran without error". Canary values (a distinctive fake-but-
 * real-looking name, a specific NI number, a specific access token) are
 * seeded across the schema and asserted GONE afterward, while relationships,
 * tenant boundaries, and financial amounts are asserted UNCHANGED.
 */
class AnonymizeDatabaseCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY_FIRST_NAME = 'Reginald';

    private const CANARY_LAST_NAME = 'Threadneedle';

    private const CANARY_NI_NUMBER = 'AB123456C';

    private const CANARY_TOKEN = 'xero-access-token-canary-value';

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    private function makeLocal(): void
    {
        $this->app['env'] = 'local';
        $this->setAppUrlEnv('http://localhost');
    }

    /**
     * env('APP_URL') (what Environment::isLocalUrl() reads) resolves via
     * $_ENV/$_SERVER before falling back to getenv() — Dotenv already
     * populated those at boot, so putenv() alone wouldn't be seen.
     */
    private function setAppUrlEnv(string $url): void
    {
        putenv("APP_URL={$url}");
        $_ENV['APP_URL'] = $url;
        $_SERVER['APP_URL'] = $url;
    }

    public function test_it_refuses_to_run_outside_a_local_environment(): void
    {
        // Default test environment is 'testing', not 'local' — the command
        // must fail closed without needing anyone to remember --force.
        $this->artisan('kase:anonymize-database', ['--force' => true])
            ->assertExitCode(1);
    }

    public function test_it_refuses_to_run_when_app_url_does_not_look_local(): void
    {
        $this->app['env'] = 'local';
        $this->setAppUrlEnv('https://app.kaselog.example');

        $this->artisan('kase:anonymize-database', ['--force' => true])
            ->assertExitCode(1);
    }

    public function test_without_force_it_asks_for_confirmation_and_aborts_on_no(): void
    {
        $this->makeLocal();

        $tenant = $this->seedTenant();
        $client = $this->seedClient($tenant);

        $this->artisan('kase:anonymize-database')
            ->expectsConfirmation('Continue?', 'no')
            ->assertExitCode(0);

        $this->assertSame(self::CANARY_FIRST_NAME, $client->fresh()->first_name);
    }

    public function test_it_scrubs_pii_preserves_relationships_and_tenant_boundaries_and_wipes_auth_secrets(): void
    {
        $this->makeLocal();

        $tenantA = $this->seedTenant('lostock-legal', 'Lostock Legal', 'LL');
        $clientA = $this->seedClient($tenantA);
        $matterA = $this->seedMatter($tenantA, $clientA);
        $callNoteA = $this->seedCallNote($tenantA, $matterA, $clientA);
        $matterMessageA = $this->seedMatterMessage($tenantA, $matterA);
        $documentA = $this->seedDocumentAiSummary($tenantA, $matterA);
        $this->seedLead($tenantA);

        $tenantB = $this->seedTenant('other-firm', 'Other Firm', 'OF');
        $clientB = $this->seedClient($tenantB, 'Beatrice', 'Cavendish');

        $user = User::factory()->create(['name' => 'Real Solicitor Name', 'email' => 'real.solicitor@example.com']);
        $user->forceFill([
            'password' => Hash::make('real-secret-password'),
            'app_authentication_secret' => 'TOTPSECRETVALUE',
            'app_authentication_recovery_codes' => ['code-one', 'code-two'],
        ])->save();
        $oldPasswordHash = $user->fresh()->password;

        CurrentTenant::set($tenantA);
        $connection = AccountingConnection::create([
            'tenant_id' => $tenantA->id,
            'provider' => AccountingProviderKey::Xero,
            'access_token' => self::CANARY_TOKEN,
            'refresh_token' => 'xero-refresh-token-canary-value',
            'external_org_id' => 'real-xero-org-id',
        ]);

        DB::table('payment_plans')->insert([
            'tenant_id' => $tenantA->id,
            'matter_id' => $matterA->id,
            'total_amount' => 1234.56,
            'deposit_amount' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        CurrentTenant::clear();

        $this->artisan('kase:anonymize-database', ['--force' => true])
            ->assertExitCode(0);

        // --- Client PII gone, relationships intact ---
        $freshClientA = $clientA->fresh();
        $this->assertNotSame(self::CANARY_FIRST_NAME, $freshClientA->first_name);
        $this->assertNotSame(self::CANARY_LAST_NAME, $freshClientA->last_name);
        $this->assertStringNotContainsString(self::CANARY_LAST_NAME, $freshClientA->email);
        $this->assertNotSame('07555123456', $freshClientA->phone);
        $this->assertNotSame('1 Real Street, Leeds', $freshClientA->address);
        $this->assertNotSame(self::CANARY_NI_NUMBER, $freshClientA->ni_number);
        $this->assertNotSame('1985-03-12', $freshClientA->date_of_birth->toDateString());
        $this->assertNotSame('Real confidential client notes.', $freshClientA->notes);
        // Tenant boundary preserved.
        $this->assertSame($tenantA->id, $freshClientA->tenant_id);

        $freshClientB = $clientB->fresh();
        $this->assertSame($tenantB->id, $freshClientB->tenant_id);
        $this->assertNotSame('Beatrice', $freshClientB->first_name);

        // --- Relationship preserved: matter still points at the SAME (now
        // scrubbed) client row, not a different one. ---
        $freshMatter = $matterA->fresh();
        $this->assertSame($freshClientA->id, $freshMatter->client_id);
        $this->assertStringNotContainsString(self::CANARY_LAST_NAME, (string) $freshMatter->notes);

        // --- Call note transcript/summary scrubbed ---
        $freshCallNote = $callNoteA->fresh();
        $this->assertStringNotContainsString(self::CANARY_LAST_NAME, $freshCallNote->transcript);
        $this->assertStringNotContainsString(self::CANARY_LAST_NAME, $freshCallNote->summary);

        // --- Matter message body scrubbed ---
        $freshMessage = $matterMessageA->fresh();
        $this->assertStringNotContainsString(self::CANARY_LAST_NAME, $freshMessage->body);

        // --- AI-extracted fields scrubbed, shape preserved ---
        $freshDocument = $documentA->fresh();
        $this->assertStringNotContainsString(self::CANARY_NI_NUMBER, json_encode($freshDocument->extracted_fields));
        $this->assertStringNotContainsString(self::CANARY_LAST_NAME, json_encode($freshDocument->key_facts));
        $this->assertStringNotContainsString(self::CANARY_NI_NUMBER, json_encode($freshDocument->field_comparisons));
        $this->assertCount(2, $freshDocument->key_facts['names']); // shape preserved
        $this->assertSame('', $freshDocument->extracted_fields['court_reference']); // blank stayed blank

        // --- Auth secrets wiped, not merely reworded ---
        $freshUser = $user->fresh();
        $this->assertNotSame($oldPasswordHash, $freshUser->password);
        $this->assertTrue(Hash::check('password', $freshUser->password));
        $this->assertNull($freshUser->app_authentication_secret);
        $this->assertNull($freshUser->app_authentication_recovery_codes);
        $this->assertNotSame('Real Solicitor Name', $freshUser->name);
        $this->assertNotSame('real.solicitor@example.com', $freshUser->email);

        // --- OAuth tokens wiped outright, not anonymized-but-present ---
        $freshConnection = $connection->fresh();
        $this->assertNull($freshConnection->access_token);
        $this->assertNull($freshConnection->refresh_token);
        $this->assertNull($freshConnection->external_org_id);

        // --- Financial amounts deliberately left untouched ---
        $paymentPlan = DB::table('payment_plans')->where('matter_id', $matterA->id)->first();
        $this->assertEquals(1234.56, $paymentPlan->total_amount);
    }

    private function seedTenant(string $slug = 'lostock-legal', string $name = 'Lostock Legal', string $prefix = 'LL'): Tenant
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'reference_prefix' => $prefix],
        );
        CurrentTenant::set($tenant);

        return $tenant;
    }

    private function seedClient(Tenant $tenant, string $first = self::CANARY_FIRST_NAME, string $last = self::CANARY_LAST_NAME): Client
    {
        CurrentTenant::set($tenant);

        return Client::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first.'.'.$last).'@real-example.com',
            'phone' => '07555123456',
            'address' => '1 Real Street, Leeds',
            'date_of_birth' => '1985-03-12',
            'ni_number' => self::CANARY_NI_NUMBER,
            'notes' => 'Real confidential client notes.',
            'source' => ClientSource::Phone,
        ]);
    }

    private function seedMatter(Tenant $tenant, Client $client): Matter
    {
        CurrentTenant::set($tenant);

        return Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
            'notes' => 'Case notes mentioning '.self::CANARY_LAST_NAME.'.',
            'title' => 'Matter for '.self::CANARY_FIRST_NAME.' '.self::CANARY_LAST_NAME,
        ]);
    }

    private function seedCallNote(Tenant $tenant, Matter $matter, Client $client): CallNote
    {
        CurrentTenant::set($tenant);

        return CallNote::create([
            'matter_id' => $matter->id,
            'client_id' => $client->id,
            'call_id' => 'call-'.uniqid(),
            'transcript' => 'Hello, this is '.self::CANARY_FIRST_NAME.' '.self::CANARY_LAST_NAME.' calling about my case.',
            'summary' => 'Call with '.self::CANARY_LAST_NAME.' regarding case status.',
        ]);
    }

    private function seedMatterMessage(Tenant $tenant, Matter $matter): MatterMessage
    {
        CurrentTenant::set($tenant);

        return MatterMessage::create([
            'matter_id' => $matter->id,
            'from_type' => 'client',
            'from_id' => $matter->client_id,
            'body' => 'Hi, '.self::CANARY_LAST_NAME.' here, checking on my matter.',
        ]);
    }

    private function seedDocumentAiSummary(Tenant $tenant, Matter $matter): DocumentAiSummary
    {
        CurrentTenant::set($tenant);

        $uploader = User::factory()->create();

        $document = MatterDocument::create([
            'matter_id' => $matter->id,
            'uploaded_by_type' => 'user',
            'uploaded_by_id' => $uploader->id,
            'filename' => self::CANARY_FIRST_NAME.'_'.self::CANARY_LAST_NAME.'_statement.pdf',
            'path' => 'matter-docs/1/statement.pdf',
        ]);

        return DocumentAiSummary::create([
            'matter_document_id' => $document->id,
            'status' => 'completed',
            'summary' => 'This statement was provided by '.self::CANARY_FIRST_NAME.' '.self::CANARY_LAST_NAME.'.',
            'key_facts' => [
                'document_type' => 'witness statement',
                'names' => [self::CANARY_FIRST_NAME.' '.self::CANARY_LAST_NAME, 'Some Other Person'],
                'dates' => ['12/03/1985'],
                'monetary_figures' => ['£5,000'],
            ],
            'extracted_fields' => [
                'ni_number' => self::CANARY_NI_NUMBER,
                'date_of_birth' => '12/03/1985',
                'phone' => '07555123456',
                'address' => '1 Real Street, Leeds',
                'court_reference' => '',
            ],
            'field_comparisons' => [
                'ni_number' => ['label' => 'NI number', 'extracted' => self::CANARY_NI_NUMBER, 'crm' => null, 'status' => 'blank_fill'],
            ],
        ]);
    }

    private function seedLead(Tenant $tenant): Lead
    {
        CurrentTenant::set($tenant);

        return Lead::create([
            'first_name' => self::CANARY_FIRST_NAME,
            'last_name' => self::CANARY_LAST_NAME,
            'email' => 'lead.'.strtolower(self::CANARY_LAST_NAME).'@real-example.com',
            'telephone' => '01611234567',
            'source' => ClientSource::Phone,
            'practice_area' => 'Family',
            'message' => 'Enquiry from '.self::CANARY_FIRST_NAME.' '.self::CANARY_LAST_NAME.'.',
            'status' => LeadStatus::New,
        ]);
    }
}
