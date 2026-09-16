<?php

namespace Tests\Feature\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for a real incident: CurrentTenant::id() read the raw
 * static property directly instead of delegating to get(), so it never got
 * get()'s fallback to Filament::getTenant(). The admin panel never calls
 * CurrentTenant::set() itself — it relies entirely on that fallback, via
 * IdentifyTenant middleware calling Filament::setTenant() from the URL's
 * tenant slug — so TenantScope's `CurrentTenant::id() === null` check
 * always failed closed for every tenant on the admin panel: empty resource
 * lists, and newly created records getting a null tenant_id via
 * BelongsToTenant's creating() hook (which also reads id()).
 */
class CurrentTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    public function test_id_reflects_filament_tenant_when_no_explicit_set_has_happened(): void
    {
        // The tenant-seeding migration already creates these as part of
        // RefreshDatabase's migration run — reuse them rather than
        // colliding on the unique slug.
        $tenant = Tenant::firstOrCreate(['slug' => 'lostock-legal'], ['name' => 'Lostock Legal', 'reference_prefix' => 'LL']);

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);

        $this->assertSame($tenant->id, CurrentTenant::id());
        $this->assertSame($tenant->id, CurrentTenant::get()?->id);
    }

    public function test_explicit_set_still_takes_priority_over_the_filament_tenant(): void
    {
        $filamentTenant = Tenant::firstOrCreate(['slug' => 'lostock-legal'], ['name' => 'Lostock Legal', 'reference_prefix' => 'LL']);
        $explicitTenant = Tenant::firstOrCreate(['slug' => 'the-motoring-lawyers'], ['name' => 'The Motoring Lawyers', 'reference_prefix' => 'TML']);

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($filamentTenant);
        CurrentTenant::set($explicitTenant);

        $this->assertSame($explicitTenant->id, CurrentTenant::id());
    }

    public function test_id_is_null_with_no_filament_tenant_and_no_explicit_set(): void
    {
        $this->assertNull(CurrentTenant::id());
    }
}
