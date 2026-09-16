<?php

namespace Tests\Unit;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Matter;
use App\Services\DocumentGenerationService;
use App\Services\MergeFieldRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * The registry is the single catalogue driving both the rich-text editor's
 * mergeTags panel and the docx flow's "Available Fields" select — if it
 * drifts from what DocumentGenerationService::resolveFields() actually
 * resolves, a solicitor could pick a field in the UI that never gets a
 * value substituted.
 */
class MergeFieldRegistryTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_every_registered_key_is_resolved_by_document_generation(): void
    {
        $this->setUpTenant();
        $this->actingAsRole('solicitor');

        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555123456', 'source' => ClientSource::Phone,
        ]);

        $matter = Matter::create([
            'client_id' => $client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active,
        ]);

        $resolved = app(DocumentGenerationService::class)->resolveFields($matter);
        $registered = array_keys(app(MergeFieldRegistry::class)->labels());

        foreach ($registered as $key) {
            $this->assertArrayHasKey($key, $resolved, "Registered merge field \"{$key}\" is never resolved.");
        }

        foreach (array_keys($resolved) as $key) {
            $this->assertContains($key, $registered, "Resolved field \"{$key}\" is missing from the registry.");
        }
    }
}
