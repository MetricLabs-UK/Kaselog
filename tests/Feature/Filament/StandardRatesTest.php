<?php

namespace Tests\Feature\Filament;

use App\Enums\BillableItem;
use App\Filament\Hub\Pages\StandardRates;
use App\Models\BillingSetting;
use App\Models\StandardRate;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\BillingRateSeeder;
use Database\Seeders\HubRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Section 18 item 4 — the global standard-rate editor. Director-only, per
 * that feature's sign-off.
 */
class StandardRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('hub'));
    }

    private function hubUser(string $role): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        return $user;
    }

    public function test_only_a_director_can_access_the_page(): void
    {
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));
        $this->assertTrue(StandardRates::canAccess());

        $this->actingAs($this->hubUser(HubAccess::ROLE_SALES));
        $this->assertFalse(StandardRates::canAccess());
    }

    public function test_it_saves_every_rate_and_the_annual_discount(): void
    {
        BillingRateSeeder::seed();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(StandardRates::class)
            ->fillForm([
                'rates' => [
                    BillableItem::SeatIndividual->value => 15,
                    BillableItem::SeatBulkBlockOf5->value => 12,
                    BillableItem::FirmWideFlat->value => 499,
                    BillableItem::AddonDocumentAiSummary->value => 0.50,
                    BillableItem::AddonQuillConversation->value => 0.25,
                    BillableItem::AddonRetellCall->value => 1.00,
                ],
                'annual_discount_percent' => 10,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('15.00', StandardRate::where('billable_item', BillableItem::SeatIndividual)->sole()->amount);
        $this->assertSame('499.00', StandardRate::where('billable_item', BillableItem::FirmWideFlat)->sole()->amount);
        $this->assertSame('10.00', BillingSetting::current()->annual_discount_percent);
    }

    public function test_the_page_loads_existing_rates_on_mount(): void
    {
        BillingRateSeeder::seed();
        StandardRate::where('billable_item', BillableItem::SeatIndividual)->update(['amount' => 20.00]);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(StandardRates::class)
            ->assertSuccessful()
            ->assertSee('20');
    }
}
