<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Regression cover for the race-safety rework of Matter::generateReference()
 * (F22a): generation now runs behind a tenant-row lock inside the save
 * transaction. True concurrency can't be exercised on the in-memory SQLite
 * test connection (lockForUpdate compiles to a no-op there), so this pins
 * the observable behaviour instead: sequential creates still yield distinct,
 * incrementing, tenant-prefixed references, and an explicit reference is
 * left untouched.
 */
class MatterReferenceGenerationTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function makeMatter(array $attributes = []): Matter
    {
        $client = Client::create([
            'first_name' => 'Ref', 'last_name' => 'Client', 'email' => uniqid().'@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
        ]);

        return Matter::create($attributes + [
            'client_id' => $client->id,
            'practice_area' => 'Motoring',
            'status' => MatterStatus::Active,
            'notes' => '',
        ]);
    }

    public function test_sequential_creates_get_distinct_incrementing_references(): void
    {
        $this->setUpTenant(['reference_prefix' => 'LL']);

        $first = $this->makeMatter();
        $second = $this->makeMatter();

        $year = now()->year;
        $this->assertSame("LL-{$year}-001", $first->reference);
        $this->assertSame("LL-{$year}-002", $second->reference);
    }

    public function test_an_explicitly_set_reference_is_not_overwritten(): void
    {
        $this->setUpTenant();

        $matter = $this->makeMatter(['reference' => 'CUSTOM-999']);

        $this->assertSame('CUSTOM-999', $matter->reference);
    }
}
