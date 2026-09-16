<?php

namespace Tests\Feature\Filament;

use App\Filament\Hub\Resources\Tenants\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 9 follow-up — the actual source-level fix for the orphaned
 * RetellCallLog.tenant_id gap: RetellWebhookController has always correctly
 * resolved and stamped a call's tenant via Tenant::findByRetellAgentId()
 * (settings->retell_agent_id) when that value exists, but nothing anywhere
 * ever let a firm's agent id be set — every real tenant's settings column
 * was genuinely null. This is the Hub form field that closes that gap; see
 * RetellWebhookControllerTest for proof the resolution itself already works
 * correctly once the value is present.
 *
 * setUp() primes spatie's permissions team id the same way
 * SetHubPermissionsTeam middleware does for a real request — see
 * HubSalesDirectorSplitTest's identical docblock for why that's needed for
 * any test driving a Hub Resource page directly through Livewire::test().
 */
class HubTenantRetellAgentIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('hub'));
        app(PermissionRegistrar::class)->setPermissionsTeamId(HubAccess::TEAM_ID);
    }

    private function hubUser(string $role): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        return $user;
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create(['name' => 'Retell Firm', 'slug' => 'retell-firm', 'reference_prefix' => 'RTF']);
    }

    public function test_a_director_can_set_the_retell_agent_id(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->fillForm(['settings' => ['retell_agent_id' => 'agent-real-firm-1']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('agent-real-firm-1', $tenant->fresh()->settings['retell_agent_id']);
    }

    public function test_setting_it_makes_the_tenant_resolvable_by_the_webhook_controllers_own_lookup(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        // Before configuring it, this firm's calls would have no
        // resolvable tenant at all — the exact orphaning this closes.
        $this->assertNull(Tenant::findByRetellAgentId('agent-real-firm-2'));

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->fillForm(['settings' => ['retell_agent_id' => 'agent-real-firm-2']])
            ->call('save')
            ->assertHasNoFormErrors();

        $resolved = Tenant::findByRetellAgentId('agent-real-firm-2');
        $this->assertNotNull($resolved);
        $this->assertSame($tenant->id, $resolved->id);
    }

    /**
     * Not gated to PERMISSION_MANAGE_FIRM_COMPLIANCE/PERMISSION_MANAGE_BILLING
     * like the Legal entity/Billing sections — a Retell agent id is routine
     * technical setup (same category as name/tagline/logo), not a sensitive
     * or compliance record, so Sales can set it too.
     */
    public function test_a_sales_user_can_also_set_it(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->hubUser(HubAccess::ROLE_SALES));

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->fillForm(['settings' => ['retell_agent_id' => 'agent-sales-set']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('agent-sales-set', $tenant->fresh()->settings['retell_agent_id']);
    }
}
