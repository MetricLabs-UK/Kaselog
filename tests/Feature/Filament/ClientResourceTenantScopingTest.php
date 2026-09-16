<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression guard for a real incident: the admin panel's client list
 * showed zero rows for every tenant except whichever one an earlier
 * CurrentTenant::set() call happened to leave behind (e.g. in a queued job
 * run earlier in the same process) — see CurrentTenantTest for the root
 * cause. This exercises the actual resource query through Filament's real
 * tenant-set flow (IdentifyTenant calls Filament::setTenant(), never
 * CurrentTenant::set() directly), for more than one tenant, so a regression
 * here can't hide behind only ever testing a single default tenant.
 */
class ClientResourceTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CurrentTenant's static state isn't reset between tests by
        // RefreshDatabase (that only resets the database) — an earlier
        // test's explicit CurrentTenant::set() (e.g. via SetsUpTenant)
        // would otherwise leak in and take priority over this test's own
        // Filament::setTenant() calls, since CurrentTenant::get() checks
        // the explicit value first.
        CurrentTenant::clear();
    }

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    public function test_each_tenants_client_list_shows_only_its_own_clients(): void
    {
        // The tenant-seeding migration already creates these as part of
        // RefreshDatabase's migration run — reuse them rather than
        // colliding on the unique slug.
        $lostock = Tenant::firstOrCreate(['slug' => 'lostock-legal'], ['name' => 'Lostock Legal', 'reference_prefix' => 'LL']);
        $tml = Tenant::firstOrCreate(['slug' => 'the-motoring-lawyers'], ['name' => 'The Motoring Lawyers', 'reference_prefix' => 'TML']);

        $director = User::factory()->create();

        TenantRoleSeeder::seed($lostock);
        TenantRoleSeeder::seed($tml);
        CurrentTenant::set($lostock);
        $director->assignRole('director');
        CurrentTenant::set($tml);
        $director->assignRole('director');
        CurrentTenant::clear();

        $this->actingAs($director);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Filament::setTenant($lostock);
        $lostockClient = Client::create([
            'first_name' => 'Lostock', 'last_name' => 'Client', 'email' => 'l@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
        ]);
        $this->assertSame($lostock->id, $lostockClient->tenant_id);

        Filament::setTenant($tml);
        $tmlClient = Client::create([
            'first_name' => 'TML', 'last_name' => 'Client', 'email' => 't@example.com',
            'phone' => '2', 'source' => ClientSource::Phone,
        ]);
        $this->assertSame($tml->id, $tmlClient->tenant_id);

        Filament::setTenant($lostock);
        $lostockIds = Livewire::test(ListClients::class)->instance()->getTable()->getQuery()->pluck('id');
        $this->assertEqualsCanonicalizing([$lostockClient->id], $lostockIds->all());

        Filament::setTenant($tml);
        $tmlIds = Livewire::test(ListClients::class)->instance()->getTable()->getQuery()->pluck('id');
        $this->assertEqualsCanonicalizing([$tmlClient->id], $tmlIds->all());
    }
}
