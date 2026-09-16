<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Filament\Admin\Pages\UnbilledTime;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TimeEntry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 6 — the unbilled-time report and its "Bundle into invoice" bulk
 * action, the entry point for turning TimeEntry rows into a real Invoice.
 */
class UnbilledTimeTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant, isQuiet: true);

        $client = Client::create([
            'first_name' => 'Report', 'last_name' => 'Client', 'email' => 'report@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
        ]);
        $this->matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Litigation', 'status' => MatterStatus::Active]);
    }

    private function makeTimeEntry(array $overrides = []): TimeEntry
    {
        return TimeEntry::create(array_merge([
            'matter_id' => $this->matter->id,
            'duration_seconds' => 3600,
            'description' => 'Work done',
            'billable' => true,
            'billing_rate' => 100,
        ], $overrides));
    }

    public function test_only_manage_invoices_holders_can_access_the_page(): void
    {
        $this->actingAsRole('accounts');
        $this->assertTrue(UnbilledTime::canAccess());

        $this->actingAsRole('director');
        $this->assertTrue(UnbilledTime::canAccess());

        $this->actingAsRole('solicitor');
        $this->assertFalse(UnbilledTime::canAccess());
    }

    public function test_the_table_only_shows_billable_unattached_entries(): void
    {
        $this->actingAsRole('director');

        $unbilled = $this->makeTimeEntry();
        $nonBillable = $this->makeTimeEntry(['billable' => false, 'billing_rate' => null]);
        $alreadyInvoiced = $this->makeTimeEntry();
        Invoice::createDraftForTimeEntries(TimeEntry::query()->whereKey($alreadyInvoiced->id)->get(), auth()->user());

        Livewire::test(UnbilledTime::class)
            ->assertCanSeeTableRecords([$unbilled])
            ->assertCanNotSeeTableRecords([$nonBillable, $alreadyInvoiced]);
    }

    public function test_bundling_selected_entries_creates_a_draft_invoice(): void
    {
        $this->actingAsRole('director');
        $entryOne = $this->makeTimeEntry();
        $entryTwo = $this->makeTimeEntry(['duration_seconds' => 1800]);

        Livewire::test(UnbilledTime::class)
            ->callTableBulkAction('bundleIntoInvoice', [$entryOne, $entryTwo]);

        $this->assertSame(1, Invoice::count());
        $this->assertTrue($entryOne->fresh()->locked);
        $this->assertTrue($entryTwo->fresh()->locked);
    }

    public function test_bundling_across_matters_is_rejected_and_creates_no_invoice(): void
    {
        $this->actingAsRole('director');
        $entryOne = $this->makeTimeEntry();

        $otherClient = Client::create([
            'first_name' => 'Other', 'last_name' => 'Client', 'email' => 'unbilled-other@example.com',
            'phone' => '2', 'source' => ClientSource::Phone,
        ]);
        $otherMatter = Matter::create(['client_id' => $otherClient->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);
        $entryTwo = $this->makeTimeEntry(['matter_id' => $otherMatter->id, 'client_id' => $otherClient->id]);

        Livewire::test(UnbilledTime::class)
            ->callTableBulkAction('bundleIntoInvoice', [$entryOne, $entryTwo]);

        $this->assertSame(0, Invoice::count());
        $this->assertFalse($entryOne->fresh()->locked);
    }
}
