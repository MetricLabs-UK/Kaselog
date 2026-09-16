<?php

namespace Tests\Feature\Webhooks;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Models\CallNote;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Matter;
use App\Models\RetellCallLog;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class RetellWebhookControllerTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private const API_KEY = 'test-retell-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.retell.api_key' => self::API_KEY]);

        // The controller resolves its tenant from the payload's agent_id —
        // this must match what analyzedPayload() sends so both the fixture
        // setup below and the webhook's own resolution land on the same tenant.
        $this->setUpTenant(['settings' => ['retell_agent_id' => 'agent-1']]);
    }

    private function analyzedPayload(array $call): array
    {
        return [
            'event' => 'call_analyzed',
            'call' => array_merge([
                'call_id' => 'call-'.uniqid(),
                'agent_id' => 'agent-1',
                'transcript' => "Agent: Hello\nCaller: Hi",
            ], $call),
        ];
    }

    private function signatureHeaders(array $payload): array
    {
        $rawBody = json_encode($payload);
        $timestamp = (int) round(microtime(true) * 1000);
        $digest = hash_hmac('sha256', $rawBody.$timestamp, self::API_KEY);

        return [
            'X-Retell-Signature' => "v={$timestamp},d={$digest}",
        ];
    }

    private function postSigned(array $payload)
    {
        return $this->postJson('/retell/webhook', $payload, $this->signatureHeaders($payload));
    }

    public function test_new_enquiry_call_creates_lead_and_call_note(): void
    {
        $payload = $this->analyzedPayload([
            'call_id' => 'call-new-enquiry',
            'call_analysis' => [
                'call_summary' => 'Caller wants advice on a family matter.',
                'custom_analysis_data' => [
                    'is_existing_client' => false,
                    'caller_name' => 'John Smith',
                    'phone_number' => '07555 123456',
                    'case_category' => 'Family',
                    'case_description' => 'Divorce enquiry',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $lead = Lead::sole();
        $this->assertSame('John', $lead->first_name);
        $this->assertSame('Smith', $lead->last_name);
        $this->assertSame('Family', $lead->practice_area);
        $this->assertSame(ClientSource::Phone, $lead->source);

        $callNote = CallNote::sole();
        $this->assertSame('call-new-enquiry', $callNote->call_id);
        $this->assertSame($lead->id, $callNote->lead_id);
        $this->assertNull($callNote->matter_id);
        $this->assertNull($callNote->client_id);
        $this->assertFalse($callNote->needs_review);

        $this->assertNotNull(RetellCallLog::where('call_id', 'call-new-enquiry')->first()?->processed_at);
    }

    public function test_existing_client_single_open_matter_auto_attaches(): void
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        $matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
        ]);

        $payload = $this->analyzedPayload([
            'call_id' => 'call-single-matter',
            'call_analysis' => [
                'call_summary' => 'Existing client calling about their case.',
                'custom_analysis_data' => [
                    'is_existing_client' => true,
                    'phone_number' => '+44 7555 123456',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $callNote = CallNote::sole();
        $this->assertSame($matter->id, $callNote->matter_id);
        $this->assertSame($client->id, $callNote->client_id);
        $this->assertFalse($callNote->needs_review);

        $this->assertSame(0, Lead::count());
    }

    public function test_existing_client_multiple_open_matters_without_category_flags_needs_review(): void
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
        ]);

        Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Conveyancing',
            'status' => MatterStatus::Suspended,
        ]);

        $payload = $this->analyzedPayload([
            'call_id' => 'call-multi-matter',
            'call_analysis' => [
                'call_summary' => 'Existing client, unclear which matter.',
                'custom_analysis_data' => [
                    'is_existing_client' => true,
                    'phone_number' => '07555123456',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $callNote = CallNote::sole();
        $this->assertNull($callNote->matter_id);
        $this->assertSame($client->id, $callNote->client_id);
        $this->assertTrue($callNote->needs_review);
        $this->assertStringContainsString('2 open matters', $callNote->review_reason);
        $this->assertStringContainsString('none provided', $callNote->review_reason);

        $this->assertSame(0, Lead::count());
    }

    public function test_existing_client_multiple_open_matters_category_disambiguates_to_single_match(): void
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
        ]);

        $drinkDriving = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Drink Driving',
            'status' => MatterStatus::Suspended,
        ]);

        $payload = $this->analyzedPayload([
            'call_id' => 'call-multi-matter-category-match',
            'call_analysis' => [
                'call_summary' => 'Existing client calling about their drink driving case.',
                'custom_analysis_data' => [
                    'is_existing_client' => true,
                    'phone_number' => '07555123456',
                    'case_category' => 'drink driving',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $callNote = CallNote::sole();
        $this->assertSame($drinkDriving->id, $callNote->matter_id);
        $this->assertSame($client->id, $callNote->client_id);
        $this->assertFalse($callNote->needs_review);
        $this->assertNull($callNote->review_reason);

        $this->assertSame(0, Lead::count());
    }

    public function test_existing_client_multiple_open_matters_category_matches_none(): void
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
        ]);

        Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Conveyancing',
            'status' => MatterStatus::Suspended,
        ]);

        $payload = $this->analyzedPayload([
            'call_id' => 'call-multi-matter-category-none',
            'call_analysis' => [
                'call_summary' => 'Existing client, category does not match any open matter.',
                'custom_analysis_data' => [
                    'is_existing_client' => true,
                    'phone_number' => '07555123456',
                    'case_category' => 'Drink Driving',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $callNote = CallNote::sole();
        $this->assertNull($callNote->matter_id);
        $this->assertSame($client->id, $callNote->client_id);
        $this->assertTrue($callNote->needs_review);
        $this->assertStringContainsString('2 open matters', $callNote->review_reason);
        $this->assertStringContainsString('Drink Driving', $callNote->review_reason);
        $this->assertStringContainsString('did not match any', $callNote->review_reason);

        $this->assertSame(0, Lead::count());
    }

    public function test_existing_client_multiple_open_matters_category_matches_more_than_one(): void
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Drink Driving',
            'status' => MatterStatus::Active,
        ]);

        Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Drink Driving',
            'status' => MatterStatus::Suspended,
        ]);

        $payload = $this->analyzedPayload([
            'call_id' => 'call-multi-matter-category-duplicate',
            'call_analysis' => [
                'call_summary' => 'Existing client with two open matters of the same category.',
                'custom_analysis_data' => [
                    'is_existing_client' => true,
                    'phone_number' => '07555123456',
                    'case_category' => 'Drink Driving',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $callNote = CallNote::sole();
        $this->assertNull($callNote->matter_id);
        $this->assertSame($client->id, $callNote->client_id);
        $this->assertTrue($callNote->needs_review);
        $this->assertStringContainsString('2 open matters', $callNote->review_reason);
        $this->assertStringContainsString('Drink Driving', $callNote->review_reason);
        $this->assertStringContainsString('matched 2 of them', $callNote->review_reason);

        $this->assertSame(0, Lead::count());
    }

    public function test_existing_client_no_phone_match_flags_needs_review_and_creates_no_lead(): void
    {
        $payload = $this->analyzedPayload([
            'call_id' => 'call-no-match',
            'call_analysis' => [
                'call_summary' => 'Caller claims to be an existing client.',
                'custom_analysis_data' => [
                    'is_existing_client' => true,
                    'phone_number' => '07999000111',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $callNote = CallNote::sole();
        $this->assertNull($callNote->matter_id);
        $this->assertNull($callNote->client_id);
        $this->assertNull($callNote->lead_id);
        $this->assertTrue($callNote->needs_review);
        $this->assertStringContainsString('no matching Client record', $callNote->review_reason);

        $this->assertSame(0, Lead::count());
    }

    public function test_outbound_call_with_matter_id_attaches_directly_and_skips_client_matching(): void
    {
        $targetMatterClient = Client::create([
            'first_name' => 'Target',
            'last_name' => 'Client',
            'email' => 'target@example.com',
            'phone' => '07111222333',
            'source' => ClientSource::Phone,
        ]);

        $targetMatter = Matter::create([
            'client_id' => $targetMatterClient->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
        ]);

        // A different client whose phone number would otherwise match, with
        // multiple open matters — proves matter_id short-circuits matching.
        $otherClient = Client::create([
            'first_name' => 'Other',
            'last_name' => 'Client',
            'email' => 'other@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        Matter::create(['client_id' => $otherClient->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);
        Matter::create(['client_id' => $otherClient->id, 'practice_area' => 'Litigation', 'status' => MatterStatus::Active]);

        $payload = $this->analyzedPayload([
            'call_id' => 'call-outbound-known-matter',
            'retell_llm_dynamic_variables' => [
                'matter_id' => (string) $targetMatter->id,
            ],
            'call_analysis' => [
                'call_summary' => 'Outbound update call.',
                'custom_analysis_data' => [
                    'is_existing_client' => true,
                    'phone_number' => '07555123456',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $callNote = CallNote::sole();
        $this->assertSame($targetMatter->id, $callNote->matter_id);
        $this->assertNull($callNote->client_id);
        $this->assertFalse($callNote->needs_review);

        $this->assertSame(0, Lead::count());
    }

    public function test_outbound_call_with_unresolvable_matter_id_flags_needs_review(): void
    {
        $payload = $this->analyzedPayload([
            'call_id' => 'call-outbound-bad-matter',
            'retell_llm_dynamic_variables' => [
                'matter_id' => '999999',
            ],
            'call_analysis' => [
                'call_summary' => 'Outbound call referencing a stale matter id.',
                'custom_analysis_data' => [],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $callNote = CallNote::sole();
        $this->assertNull($callNote->matter_id);
        $this->assertTrue($callNote->needs_review);
        $this->assertStringContainsString('999999', $callNote->review_reason);
        $this->assertStringContainsString('not found', $callNote->review_reason);

        $this->assertSame(0, Lead::count());
    }

    public function test_duplicate_call_analyzed_event_does_not_reprocess(): void
    {
        $payload = $this->analyzedPayload([
            'call_id' => 'call-duplicate',
            'call_analysis' => [
                'call_summary' => 'First delivery of this event.',
                'custom_analysis_data' => [
                    'is_existing_client' => false,
                    'caller_name' => 'Dupe Caller',
                    'phone_number' => '07000111222',
                    'case_category' => 'Family',
                ],
            ],
        ]);

        $first = $this->postSigned($payload);
        $first->assertOk();

        $this->assertSame(1, CallNote::count());
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, RetellCallLog::where('call_id', 'call-duplicate')->count());

        // Retell redelivers the same event — must not create a second Lead/CallNote.
        $second = $this->postSigned($payload);
        $second->assertOk();

        $this->assertSame(1, CallNote::count());
        $this->assertSame(1, Lead::count());
        $this->assertSame(2, RetellCallLog::where('call_id', 'call-duplicate')->count());
    }

    public function test_invalid_signature_is_rejected_and_nothing_is_processed(): void
    {
        $payload = $this->analyzedPayload([
            'call_id' => 'call-bad-signature',
            'call_analysis' => [
                'call_summary' => 'Should never be processed.',
                'custom_analysis_data' => [
                    'is_existing_client' => false,
                    'caller_name' => 'Should Not',
                    'phone_number' => '07000000000',
                ],
            ],
        ]);

        $response = $this->postJson('/retell/webhook', $payload, [
            'X-Retell-Signature' => 'v=1,d=deadbeef',
        ]);

        $response->assertStatus(401)->assertJson(['status' => 'invalid signature']);

        $this->assertSame(0, RetellCallLog::count());
        $this->assertSame(0, CallNote::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        $payload = $this->analyzedPayload([
            'call_id' => 'call-no-signature',
        ]);

        $response = $this->postJson('/retell/webhook', $payload);

        $response->assertStatus(401)->assertJson(['status' => 'invalid signature']);

        $this->assertSame(0, RetellCallLog::count());
        $this->assertSame(0, CallNote::count());
    }

    /**
     * The cross-match risk the controller's own comment warns about: client
     * phone matching must run inside the calling agent's tenant scope, or a
     * phone number that happens to exist in both brands could attach the
     * call to the wrong firm's client. The same number is deliberately
     * seeded in BOTH tenants here, and the call arrives on the *second*
     * tenant's agent — so a scope leak (or first-match-wins lookup) would
     * pick tenant one's older client row and fail these assertions.
     */
    public function test_shared_phone_number_across_tenants_attaches_to_the_calling_agents_tenant_only(): void
    {
        $sharedPhone = '07585 637580';

        $clientA = Client::create([
            'first_name' => 'Lostock',
            'last_name' => 'Client',
            'email' => 'lostock@example.com',
            'phone' => $sharedPhone,
            'source' => ClientSource::Phone,
        ]);
        Matter::create([
            'client_id' => $clientA->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
        ]);

        $tenantB = Tenant::firstOrCreate(
            ['slug' => 'the-motoring-lawyers'],
            ['name' => 'The Motoring Lawyers', 'reference_prefix' => 'TML'],
        );
        $tenantB->update(['settings' => ['retell_agent_id' => 'agent-2']]);

        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($tenantB);

        try {
            $clientB = Client::create([
                'first_name' => 'TML',
                'last_name' => 'Client',
                'email' => 'tml@example.com',
                'phone' => $sharedPhone,
                'source' => ClientSource::Phone,
            ]);
            $matterB = Matter::create([
                'client_id' => $clientB->id,
                'practice_area' => 'Motoring',
                'status' => MatterStatus::Active,
            ]);
        } finally {
            CurrentTenant::set($previousTenant);
        }

        $payload = $this->analyzedPayload([
            'call_id' => 'call-cross-tenant',
            'agent_id' => 'agent-2',
            'call_analysis' => [
                'call_summary' => 'Existing client calling about their case.',
                'custom_analysis_data' => [
                    'is_existing_client' => true,
                    'phone_number' => '+44 7585 637580',
                ],
            ],
        ]);

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['status' => 'ok']);

        // Assertions read across tenants deliberately — the point is where
        // the note landed, not what one tenant's scope can see.
        $callNote = CallNote::allTenants()->sole();
        $this->assertSame($tenantB->id, $callNote->tenant_id);
        $this->assertSame($clientB->id, $callNote->client_id);
        $this->assertSame($matterB->id, $callNote->matter_id);
        $this->assertFalse($callNote->needs_review);

        $this->assertSame($tenantB->id, RetellCallLog::allTenants()->sole()->tenant_id);
        $this->assertSame(0, Lead::allTenants()->count());
    }
}
