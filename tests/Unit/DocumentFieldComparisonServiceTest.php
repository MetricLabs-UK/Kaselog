<?php

namespace Tests\Unit;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Matter;
use App\Services\DocumentFieldComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Feature A — the actual decision logic behind "only surface a to-do when
 * there's a genuine mismatch or a currently-blank field being filled". A
 * clean match across every extracted field must produce needs_review=false
 * and no review_reason at all — that's the whole point of this service.
 */
class DocumentFieldComparisonServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private DocumentFieldComparisonService $service;

    private Client $client;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
        $this->service = new DocumentFieldComparisonService;

        $this->client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555 123456', 'address' => '1 High Street, Leeds, LS1 1AA',
            'source' => ClientSource::Phone,
        ]);

        $this->matter = Matter::create([
            'client_id' => $this->client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active,
            'urn' => 'CASE-2026-001',
        ]);
    }

    private function extracted(array $overrides = []): array
    {
        return array_merge([
            'ni_number' => '', 'date_of_birth' => '', 'phone' => '', 'address' => '', 'court_reference' => '',
        ], $overrides);
    }

    public function test_a_clean_match_across_every_field_needs_no_review(): void
    {
        $result = $this->service->compare(
            $this->extracted(['phone' => '07555123456', 'court_reference' => 'case-2026-001']),
            $this->client,
            $this->matter,
        );

        $this->assertFalse($result['needs_review']);
        $this->assertNull($result['review_reason']);
        $this->assertSame('match', $result['field_comparisons']['phone']['status']);
        $this->assertSame('match', $result['field_comparisons']['court_reference']['status']);
    }

    public function test_a_field_the_document_has_but_the_crm_lacks_is_a_blank_fill(): void
    {
        $result = $this->service->compare(
            $this->extracted(['ni_number' => 'AB123456C']),
            $this->client,
            $this->matter,
        );

        $this->assertTrue($result['needs_review']);
        $this->assertSame('blank_fill', $result['field_comparisons']['ni_number']['status']);
        $this->assertStringContainsString('NI number is blank on the CRM record', $result['review_reason']);
        $this->assertStringContainsString('AB123456C', $result['review_reason']);
    }

    public function test_a_field_that_genuinely_differs_from_the_crm_is_a_mismatch(): void
    {
        $result = $this->service->compare(
            $this->extracted(['phone' => '07999888777']),
            $this->client,
            $this->matter,
        );

        $this->assertTrue($result['needs_review']);
        $this->assertSame('mismatch', $result['field_comparisons']['phone']['status']);
        $this->assertStringContainsString('Phone differs from the CRM record', $result['review_reason']);
    }

    public function test_a_field_the_document_never_mentioned_is_not_compared_at_all(): void
    {
        $result = $this->service->compare($this->extracted(), $this->client, $this->matter);

        $this->assertFalse($result['needs_review']);
        $this->assertSame([], $result['field_comparisons']);
        $this->assertSame([], $result['extracted_fields']);
    }

    public function test_multiple_mismatches_are_combined_into_one_review_reason(): void
    {
        $result = $this->service->compare(
            $this->extracted(['phone' => '07999888777', 'ni_number' => 'AB123456C']),
            $this->client,
            $this->matter,
        );

        $this->assertStringContainsString('Phone differs', $result['review_reason']);
        $this->assertStringContainsString('NI number is blank', $result['review_reason']);
    }

    public function test_phone_comparison_is_normalized_the_same_way_as_call_matching(): void
    {
        // CRM has "07555 123456" (with a space); document says "+44 7555 123456".
        $result = $this->service->compare(
            $this->extracted(['phone' => '+44 7555 123456']),
            $this->client,
            $this->matter,
        );

        $this->assertSame('match', $result['field_comparisons']['phone']['status']);
    }

    public function test_date_of_birth_compares_correctly_regardless_of_the_format_the_ai_wrote(): void
    {
        $this->client->update(['date_of_birth' => '1985-03-12']);

        $result = $this->service->compare(
            $this->extracted(['date_of_birth' => '12/03/1985']),
            $this->client,
            $this->matter,
        );

        $this->assertSame('match', $result['field_comparisons']['date_of_birth']['status']);
    }

    public function test_court_reference_compares_against_the_matters_urn(): void
    {
        $result = $this->service->compare(
            $this->extracted(['court_reference' => 'CASE-2026-999']),
            $this->client,
            $this->matter,
        );

        $this->assertSame('mismatch', $result['field_comparisons']['court_reference']['status']);
    }
}
