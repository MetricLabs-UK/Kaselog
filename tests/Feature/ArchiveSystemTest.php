<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Matter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 13 (Archive System). Same global-scope pattern
 * ExcludeConvertedLeadsScope already established for Lead — see
 * App\Models\Concerns\Archivable and App\Models\Scopes\ExcludeArchivedScope.
 * Matter is the one driven end-to-end through the real Livewire action here;
 * Client/Lead/PaymentPlan share the exact same App\Filament\Support\
 * ArchiveActions factory and Archivable trait, so this is the same code
 * path for all four, not a coincidence worth re-proving four times.
 */
class ArchiveSystemTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function matter(): Matter
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        return Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => 'active',
        ]);
    }

    public function test_archiving_hides_a_matter_from_the_default_query_but_not_from_withArchived(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $matter = $this->matter();

        $matter->archive($director);

        $this->assertNull(Matter::find($matter->id));
        $this->assertNotNull(Matter::withArchived()->find($matter->id));
    }

    public function test_archiving_and_restoring_sets_and_clears_archived_fields(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $matter = $this->matter();
        $matter->archive($director);

        $archived = Matter::withArchived()->find($matter->id);
        $this->assertTrue($archived->isArchived());
        $this->assertSame($director->id, $archived->archived_by);
        $this->assertNotNull($archived->archived_at);

        $archived->restore();

        $restored = Matter::find($matter->id);
        $this->assertFalse($restored->isArchived());
        $this->assertNull($restored->archived_by);
        $this->assertNull($restored->archived_at);
    }

    public function test_archive_and_restore_log_as_their_own_distinct_events_not_a_generic_update(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $matter = $this->matter();
        $matter->archive($director);
        Matter::withArchived()->find($matter->id)->restore();

        $archivedActivity = Activity::query()->inLog('matters')->forEvent('archived')->forSubject($matter)->sole();
        $this->assertSame($director->id, $archivedActivity->causer_id);
        $this->assertSame($this->tenant->id, $archivedActivity->tenant_id);

        $restoredActivity = Activity::query()->inLog('matters')->forEvent('restored')->forSubject($matter)->sole();
        $this->assertSame($this->tenant->id, $restoredActivity->tenant_id);

        // Neither is logged as a plain 'updated' diff.
        $this->assertSame(0, Activity::query()->inLog('matters')->forEvent('updated')->forSubject($matter)->count());
    }

    public function test_a_status_change_does_not_archive_and_archiving_does_not_change_status(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $matter = $this->matter();
        $matter->update(['status' => 'closed']);

        $this->assertFalse($matter->fresh()->isArchived());

        $matter->archive($director);

        $this->assertSame('closed', Matter::withArchived()->find($matter->id)->status->value);
    }

    public function test_hard_delete_requires_force_delete_records_not_the_old_delete_permission(): void
    {
        $this->setUpTenant();
        $matter = $this->matter();

        // admin holds delete_matters (still used for child-record deletion
        // elsewhere) but must NOT be able to hard-delete the Matter itself.
        $this->actingAsRole('admin');
        $this->assertTrue(auth()->user()->can('delete_matters'));
        $this->assertFalse(MatterResource::canDelete($matter));

        $director = $this->actingAsRole('director');
        $this->assertTrue($director->can('force_delete_records'));
        $this->assertTrue(MatterResource::canDelete($matter));
    }

    public function test_archiving_through_the_real_edit_page_action_works_end_to_end(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        $matter = $this->matter();

        Livewire::test(EditMatter::class, ['record' => $matter->getKey()])
            ->callAction('archive');

        $this->assertNull(Matter::find($matter->id));

        $activity = Activity::query()->inLog('matters')->forEvent('archived')->forSubject($matter)->sole();
        $this->assertSame($director->id, $activity->causer_id);
    }

    public function test_restoring_through_the_real_archived_tab_action_works_end_to_end(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        $matter = $this->matter();
        $matter->archive($director);

        $this->assertNull(Matter::find($matter->id), 'sanity check: archived matter should not be in the default list');

        Livewire::test(ListMatters::class)
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$matter])
            ->callTableAction('restore', $matter);

        $restored = Matter::find($matter->id);
        $this->assertNotNull($restored, 'restore should make the matter visible in the default query again');
        $this->assertFalse($restored->isArchived());

        $activity = Activity::query()->inLog('matters')->forEvent('restored')->forSubject($matter)->sole();
        $this->assertSame($director->id, $activity->causer_id);
    }

    public function test_the_archived_tab_is_only_offered_to_directors(): void
    {
        $this->setUpTenant();
        $this->actingAsRole('admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        $tabs = Livewire::test(ListMatters::class)->instance()->getTabs();

        $this->assertArrayNotHasKey('archived', $tabs);
    }

    public function test_forcing_the_archived_tab_key_does_not_leak_archived_records_to_a_non_director(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        $matter = $this->matter();
        $matter->archive($director);

        $this->actingAsRole('admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        // Filament resolves tabs server-side from the current user's own
        // getTabs() output — a non-director forcing this Livewire property
        // client-side can't make a tab exist that their own request didn't
        // offer, so this must fall through to the unmodified (archived-
        // excluding) query rather than actually applying the archived tab's
        // query modification.
        Livewire::test(ListMatters::class)
            ->set('activeTab', 'archived')
            ->assertCanNotSeeTableRecords([$matter]);
    }
}
