<?php

namespace Tests\Feature;

use App\Filament\Hub\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 19 — impersonation must be as tightly gated as Hub access itself
 * (item 18's own bar, per the audit note): only hub_director, never
 * hub_sales, and never a firm's own tenant-level director (who can't reach
 * the Hub at all — see HubPanelAccessTest, the same rigor this mirrors).
 */
class ImpersonationAccessTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function hubUser(string $role): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        return $user;
    }

    public function test_hub_director_can_impersonate(): void
    {
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);

        $this->assertTrue($director->hasHubPermission(HubAccess::PERMISSION_IMPERSONATE));
        $this->assertTrue($director->canImpersonate());
    }

    public function test_hub_sales_cannot_impersonate(): void
    {
        $sales = $this->hubUser(HubAccess::ROLE_SALES);

        $this->assertFalse($sales->hasHubPermission(HubAccess::PERMISSION_IMPERSONATE));
        $this->assertFalse($sales->canImpersonate());
    }

    public function test_a_firms_own_director_cannot_impersonate(): void
    {
        $this->setUpTenant();
        $firmDirector = $this->actingAsRole('director');

        $this->assertFalse($firmDirector->canImpersonate());
    }

    public function test_request_impersonation_action_is_hidden_for_hub_sales(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $sales = $this->hubUser(HubAccess::ROLE_SALES);
        $this->actingAs($sales);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('hub'));

        Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $tenant,
            'pageClass' => \App\Filament\Hub\Resources\Tenants\Pages\EditTenant::class,
            'lazy' => false,
        ])->assertTableActionHidden('requestImpersonation', $target);
    }

    public function test_request_impersonation_action_is_visible_for_hub_director(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $this->actingAs($director);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('hub'));

        Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $tenant,
            'pageClass' => \App\Filament\Hub\Resources\Tenants\Pages\EditTenant::class,
            'lazy' => false,
        ])->assertTableActionVisible('requestImpersonation', $target);
    }

    public function test_enter_session_route_rejects_a_director_who_didnt_request_it(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $requestingDirector = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $otherDirector = $this->hubUser(HubAccess::ROLE_DIRECTOR);

        $session = ImpersonationSession::requestFor($requestingDirector, $target, $tenant, 'Investigating a support ticket.');
        $session->accept();

        $this->actingAs($otherDirector);

        $this->get(route('impersonation.enter', $session))->assertForbidden();
    }

    public function test_enter_session_route_rejects_hub_sales_entirely(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $sales = $this->hubUser(HubAccess::ROLE_SALES);

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');
        $session->accept();

        // requested_by is the director, but PERMISSION_IMPERSONATE is
        // re-checked at entry too, not just at request time.
        $session->forceFill(['requested_by' => $sales->id])->saveQuietly();

        $this->actingAs($sales);

        $this->get(route('impersonation.enter', $session))->assertForbidden();
    }
}
