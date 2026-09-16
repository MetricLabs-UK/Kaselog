<?php

namespace Tests\Feature\Filament;

use App\Enums\BillableItem;
use App\Enums\BillingCadence;
use App\Enums\PurchaseMode;
use App\Enums\SeatPurchaseType;
use App\Enums\SubscriptionStatus;
use App\Filament\Hub\Resources\Tenants\Pages\ManageTenantBilling;
use App\Models\StandardRate;
use App\Models\Tenant;
use App\Models\TenantAddonSubscription;
use App\Models\TenantBillingOverride;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\BillingRateSeeder;
use Database\Seeders\HubRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Section 18 item 4 — the per-tenant billing management page. Director-only.
 * Display-and-manage only, deliberately no invoicing/payment here.
 */
class ManageTenantBillingTest extends TestCase
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

    private function makeTenant(string $slug = 'billing-ui-firm'): Tenant
    {
        return Tenant::create(['name' => 'Billing UI Firm', 'slug' => $slug, 'reference_prefix' => strtoupper(substr($slug, 0, 3))]);
    }

    public function test_only_a_director_can_access_the_page(): void
    {
        $tenant = $this->makeTenant();

        $this->actingAs($this->hubUser(HubAccess::ROLE_SALES));
        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->assertForbidden();

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));
        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->assertSuccessful();
    }

    public function test_it_shows_no_subscription_started_yet_for_a_new_firm(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->assertSee('No subscription started yet.');
    }

    public function test_starting_a_subscription_creates_an_active_one(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->callAction('startSubscription', data: [
                'purchase_mode' => PurchaseMode::SeatBased->value,
                'billing_cadence' => BillingCadence::Monthly->value,
            ]);

        $subscription = $tenant->currentSubscription();
        $this->assertNotNull($subscription);
        $this->assertSame(PurchaseMode::SeatBased, $subscription->purchase_mode);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    public function test_starting_a_second_subscription_ends_the_first(): void
    {
        $tenant = $this->makeTenant();
        $first = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->callAction('startSubscription', data: [
                'purchase_mode' => PurchaseMode::FlatFirmWide->value,
                'billing_cadence' => BillingCadence::Annual->value,
            ]);

        $this->assertSame(SubscriptionStatus::Ended, $first->fresh()->status);
        $this->assertSame(PurchaseMode::FlatFirmWide, $tenant->currentSubscription()->purchase_mode);
    }

    public function test_ending_a_subscription_is_only_offered_when_one_is_active(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->assertActionHidden('endSubscription');

        TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->assertActionVisible('endSubscription')
            ->callAction('endSubscription');

        $this->assertNull($tenant->currentSubscription());
    }

    public function test_buy_seats_is_only_offered_for_a_seat_based_active_subscription(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->assertActionHidden('buySeats');

        TenantSubscription::startFor($tenant, PurchaseMode::FlatFirmWide, BillingCadence::Monthly);

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->assertActionHidden('buySeats');
    }

    public function test_buying_seats_records_a_purchase_at_the_resolved_rate(): void
    {
        BillingRateSeeder::seed();
        StandardRate::where('billable_item', BillableItem::SeatBulkBlockOf5)->update(['amount' => 12.00]);

        $tenant = $this->makeTenant();
        $subscription = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->callAction('buySeats', data: [
                'purchase_type' => SeatPurchaseType::BulkBlockOf5->value,
                'quantity' => 2,
            ]);

        $this->assertSame(10, $subscription->fresh()->totalSeats());
        $purchase = $subscription->seatPurchases()->sole();
        $this->assertSame('12.00', $purchase->rate_applied);
    }

    public function test_buying_seats_with_no_rate_set_is_refused(): void
    {
        BillingRateSeeder::seed();

        $tenant = $this->makeTenant();
        $subscription = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->callAction('buySeats', data: [
                'purchase_type' => SeatPurchaseType::Individual->value,
                'quantity' => 1,
            ]);

        $this->assertSame(0, $subscription->fresh()->totalSeats());
    }

    public function test_toggling_an_addon_flips_its_enabled_state(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->callAction('toggleAddon', data: ['billable_item' => BillableItem::AddonQuillConversation->value]);

        $this->assertTrue(TenantAddonSubscription::isEnabledFor($tenant, BillableItem::AddonQuillConversation));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->callAction('toggleAddon', data: ['billable_item' => BillableItem::AddonQuillConversation->value]);

        $this->assertFalse(TenantAddonSubscription::isEnabledFor($tenant, BillableItem::AddonQuillConversation));
    }

    public function test_setting_an_override_requires_a_note(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->callAction('setOverride', data: [
                'billable_item' => BillableItem::SeatIndividual->value,
                'amount' => 9.00,
                'note' => '',
            ])
            ->assertHasActionErrors(['note' => 'required']);

        $this->assertSame(0, TenantBillingOverride::where('tenant_id', $tenant->id)->count());
    }

    public function test_setting_and_removing_an_override(): void
    {
        $tenant = $this->makeTenant();
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $this->actingAs($director);

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->callAction('setOverride', data: [
                'billable_item' => BillableItem::SeatIndividual->value,
                'amount' => 9.00,
                'note' => 'Negotiated volume deal',
            ]);

        $override = TenantBillingOverride::where('tenant_id', $tenant->id)->sole();
        $this->assertSame('9.00', $override->amount);
        $this->assertSame($director->id, $override->created_by);

        Livewire::test(ManageTenantBilling::class, ['record' => $tenant->getKey()])
            ->assertSee('9.00')
            ->assertSee('Negotiated volume deal')
            ->callAction('removeOverride', data: ['billable_item' => BillableItem::SeatIndividual->value]);

        $this->assertSame(0, TenantBillingOverride::where('tenant_id', $tenant->id)->count());
    }

    public function test_pooled_trading_brand_bills_through_its_parent(): void
    {
        $parent = $this->makeTenant('billing-ui-parent');
        $brand = Tenant::create([
            'name' => 'Billing UI Brand',
            'slug' => 'billing-ui-brand',
            'reference_prefix' => 'BUB',
            'parent_tenant_id' => $parent->id,
            'pools_billing_with_parent' => true,
        ]);

        $this->assertSame($parent->id, $brand->billingTenant()->id);
    }
}
