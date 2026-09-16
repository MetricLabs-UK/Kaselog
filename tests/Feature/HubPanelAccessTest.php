<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * The Hub panel must be closed to everyone except an account explicitly
 * granted a hub_* role — including a firm's own director, who holds the
 * most powerful role that exists inside their tenant but has no standing in
 * the Hub whatsoever. Enforcement is Filament\Http\Middleware\Authenticate
 * calling User::canAccessPanel(), which aborts 403 rather than merely hiding
 * navigation — proven here by hitting the route directly.
 */
class HubPanelAccessTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_guest_is_redirected_to_hub_login(): void
    {
        $this->get('/hub')->assertRedirect('/hub/login');
    }

    public function test_a_firm_director_cannot_access_the_hub(): void
    {
        $this->setUpTenant();
        $this->actingAsRole('director');

        $this->get('/hub')->assertForbidden();
    }

    public function test_a_plain_firm_user_cannot_access_the_hub(): void
    {
        $this->setUpTenant();
        $this->actingAsRole('solicitor');

        $this->get('/hub')->assertForbidden();
    }

    public function test_a_hub_sales_user_can_access_the_hub_but_not_a_firms_admin_panel(): void
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        // MFA is required panel-wide (HubMultiFactorAuthTest covers that
        // separately) — confirmed here so this test isn't blocked by it.
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole(HubAccess::ROLE_SALES));

        $this->actingAs($user);

        $this->get('/hub')->assertSuccessful();

        // No tenant, no firm role: the admin panel must reject them too —
        // holding a Hub role grants nothing inside any firm.
        // Filament's own IdentifyTenant middleware 404s (not 403) a user who
        // can't access the requested tenant, so that's the assertion here.
        $this->setUpTenant();
        $this->get('/admin/'.$this->tenant->slug)->assertNotFound();
    }

    public function test_a_hub_director_can_access_the_hub_and_the_tenants_resource(): void
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole(HubAccess::ROLE_DIRECTOR));

        $this->actingAs($user);

        $this->get('/hub')->assertSuccessful();
        $this->get('/hub/tenants')->assertSuccessful();
    }
}
