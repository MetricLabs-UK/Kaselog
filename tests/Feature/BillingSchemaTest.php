<?php

namespace Tests\Feature;

use App\Enums\BillableItem;
use App\Enums\BillingCadence;
use App\Enums\PurchaseMode;
use App\Enums\SeatPurchaseType;
use App\Enums\SubscriptionStatus;
use App\Models\BillingSetting;
use App\Models\StandardRate;
use App\Models\Tenant;
use App\Models\TenantAddonSubscription;
use App\Models\TenantBillingOverride;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\Billing\RateResolver;
use Database\Seeders\BillingRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Section 18 item 4's pricing v1 schema — the plans concept, the
 * override-if-exists-else-standard resolution rule, the seat ledger, and the
 * lapse-resets-grandfathering structural guarantee. No Hub UI yet — that's
 * deliberately deferred until this is signed off.
 */
class BillingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug = 'billing-firm'): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'reference_prefix' => strtoupper(substr($slug, 0, 3))]);
    }

    public function test_the_seeder_creates_one_placeholder_rate_per_billable_item(): void
    {
        BillingRateSeeder::seed();

        $this->assertSame(count(BillableItem::cases()), StandardRate::count());

        foreach (BillableItem::cases() as $item) {
            $rate = StandardRate::where('billable_item', $item)->sole();
            $this->assertNull($rate->amount);
        }

        $this->assertNotNull(BillingSetting::current());
        $this->assertNull(BillingSetting::current()->annual_discount_percent);
    }

    public function test_the_seeder_is_idempotent_and_never_overwrites_a_real_figure(): void
    {
        BillingRateSeeder::seed();

        StandardRate::where('billable_item', BillableItem::SeatIndividual)->update(['amount' => 12.50]);

        BillingRateSeeder::seed();

        $this->assertSame('12.50', StandardRate::where('billable_item', BillableItem::SeatIndividual)->sole()->amount);
        $this->assertSame(count(BillableItem::cases()), StandardRate::count());
    }

    public function test_rate_resolver_returns_null_when_nothing_is_priced_yet(): void
    {
        BillingRateSeeder::seed();
        $tenant = $this->makeTenant();

        $this->assertNull(RateResolver::rateFor($tenant, BillableItem::SeatIndividual));
    }

    public function test_rate_resolver_falls_back_to_the_standard_rate(): void
    {
        BillingRateSeeder::seed();
        StandardRate::where('billable_item', BillableItem::SeatIndividual)->update(['amount' => 15.00]);

        $tenant = $this->makeTenant();

        $this->assertSame(15.00, RateResolver::rateFor($tenant, BillableItem::SeatIndividual));
    }

    public function test_rate_resolver_prefers_a_tenant_override_over_the_standard_rate(): void
    {
        BillingRateSeeder::seed();
        StandardRate::where('billable_item', BillableItem::SeatIndividual)->update(['amount' => 15.00]);

        $tenant = $this->makeTenant();
        TenantBillingOverride::setOverride($tenant, BillableItem::SeatIndividual, 9.00, 'Negotiated deal', null);

        $this->assertSame(9.00, RateResolver::rateFor($tenant, BillableItem::SeatIndividual));

        // A different tenant is unaffected — this is a per-firm override,
        // not a new public rate.
        $otherTenant = $this->makeTenant('other-billing-firm');
        $this->assertSame(15.00, RateResolver::rateFor($otherTenant, BillableItem::SeatIndividual));
    }

    public function test_setting_an_override_twice_replaces_it_rather_than_duplicating(): void
    {
        $tenant = $this->makeTenant();

        TenantBillingOverride::setOverride($tenant, BillableItem::FirmWideFlat, 500.00, 'First deal', null);
        TenantBillingOverride::setOverride($tenant, BillableItem::FirmWideFlat, 450.00, 'Renegotiated', null);

        $this->assertSame(1, TenantBillingOverride::where('tenant_id', $tenant->id)->count());
        $this->assertSame(450.00, RateResolver::rateFor($tenant, BillableItem::FirmWideFlat));
    }

    public function test_a_subscription_records_who_created_an_override(): void
    {
        $tenant = $this->makeTenant();
        $director = User::factory()->create();

        $override = TenantBillingOverride::setOverride($tenant, BillableItem::AddonRetellCall, 0.50, null, $director);

        $this->assertSame($director->id, $override->createdBy->id);
    }

    public function test_starting_a_subscription_ends_any_existing_active_one(): void
    {
        $tenant = $this->makeTenant();

        $first = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);
        $second = TenantSubscription::startFor($tenant, PurchaseMode::FlatFirmWide, BillingCadence::Annual);

        $first->refresh();
        $this->assertSame(SubscriptionStatus::Ended, $first->status);
        $this->assertNotNull($first->ended_at);

        $this->assertSame(SubscriptionStatus::Active, $second->fresh()->status);
        $this->assertSame($second->id, $tenant->currentSubscription()->id);
        $this->assertSame(1, TenantSubscription::where('tenant_id', $tenant->id)->where('status', SubscriptionStatus::Active)->count());
    }

    public function test_total_seats_sums_individual_and_bulk_purchases(): void
    {
        $tenant = $this->makeTenant();
        $subscription = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);

        $subscription->recordSeatPurchase(SeatPurchaseType::BulkBlockOf5, 2, 12.00);
        $subscription->recordSeatPurchase(SeatPurchaseType::Individual, 3, 15.00);

        $this->assertSame(13, $subscription->totalSeats());
    }

    public function test_a_seat_purchase_freezes_the_rate_it_was_bought_at(): void
    {
        BillingRateSeeder::seed();
        StandardRate::where('billable_item', BillableItem::SeatIndividual)->update(['amount' => 15.00]);

        $tenant = $this->makeTenant();
        $subscription = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);
        $rateAtPurchase = RateResolver::rateFor($tenant, BillableItem::SeatIndividual);
        $purchase = $subscription->recordSeatPurchase(SeatPurchaseType::Individual, 1, $rateAtPurchase);

        // Standard rate goes up afterwards — the historical purchase must
        // not move.
        StandardRate::where('billable_item', BillableItem::SeatIndividual)->update(['amount' => 25.00]);

        $this->assertSame(15.00, (float) $purchase->fresh()->rate_applied);
        $this->assertSame(25.00, RateResolver::rateFor($tenant, BillableItem::SeatIndividual));
    }

    public function test_grandfathering_does_not_survive_a_lapse_and_resubscribe(): void
    {
        BillingRateSeeder::seed();
        StandardRate::where('billable_item', BillableItem::SeatIndividual)->update(['amount' => 15.00]);

        $tenant = $this->makeTenant();
        $original = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);
        $original->recordSeatPurchase(SeatPurchaseType::Individual, 1, 15.00);

        // Firm lapses, standard rate rises, firm comes back.
        $original->end();
        StandardRate::where('billable_item', BillableItem::SeatIndividual)->update(['amount' => 25.00]);

        $resubscribed = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);

        $this->assertNotSame($original->id, $resubscribed->id);
        $this->assertSame(0, $resubscribed->totalSeats());
        $this->assertSame(25.00, RateResolver::rateFor($tenant, BillableItem::SeatIndividual));

        // The old subscription's ledger (and its frozen rate) is untouched.
        $this->assertSame(1, $original->fresh()->totalSeats());
    }

    public function test_a_seat_purchase_cannot_be_recorded_against_an_ended_subscription(): void
    {
        $tenant = $this->makeTenant();
        $subscription = TenantSubscription::startFor($tenant, PurchaseMode::SeatBased, BillingCadence::Monthly);
        $subscription->end();

        $this->expectException(LogicException::class);

        $subscription->recordSeatPurchase(SeatPurchaseType::Individual, 1, 15.00);
    }

    public function test_addon_subscriptions_track_enable_disable_history(): void
    {
        $tenant = $this->makeTenant();

        $this->assertFalse(TenantAddonSubscription::isEnabledFor($tenant, BillableItem::AddonQuillConversation));

        TenantAddonSubscription::enable($tenant, BillableItem::AddonQuillConversation);
        $this->assertTrue(TenantAddonSubscription::isEnabledFor($tenant, BillableItem::AddonQuillConversation));

        TenantAddonSubscription::disable($tenant, BillableItem::AddonQuillConversation);
        $this->assertFalse(TenantAddonSubscription::isEnabledFor($tenant, BillableItem::AddonQuillConversation));

        // Re-enabling leaves the old cycle in place rather than overwriting it.
        TenantAddonSubscription::enable($tenant, BillableItem::AddonQuillConversation);
        $this->assertTrue(TenantAddonSubscription::isEnabledFor($tenant, BillableItem::AddonQuillConversation));
        $this->assertSame(2, TenantAddonSubscription::where('tenant_id', $tenant->id)->count());
    }

    public function test_enabling_a_non_addon_billable_item_is_rejected(): void
    {
        $tenant = $this->makeTenant();

        $this->expectException(InvalidArgumentException::class);

        TenantAddonSubscription::enable($tenant, BillableItem::SeatIndividual);
    }

    public function test_a_trading_brand_bills_through_its_parent_only_when_pooling_is_enabled(): void
    {
        $parent = $this->makeTenant('billing-parent');
        $brand = Tenant::create([
            'name' => 'Billing Brand',
            'slug' => 'billing-brand',
            'reference_prefix' => 'BRA',
            'parent_tenant_id' => $parent->id,
        ]);

        $this->assertSame($brand->id, $brand->billingTenant()->id);

        $brand->update(['pools_billing_with_parent' => true]);
        $this->assertSame($parent->id, $brand->fresh()->billingTenant()->id);
    }
}
