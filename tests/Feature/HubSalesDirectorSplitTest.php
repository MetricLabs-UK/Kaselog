<?php

namespace Tests\Feature;

use App\Filament\Hub\Resources\Tenants\Pages\EditTenant;
use App\Filament\Hub\Resources\Tenants\RelationManagers\UsersRelationManager;
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
 * Section 18 item 1 — the real Sales/Director boundary. Sales can view firm
 * lists/counts and set up new firms/licences; must not see pricing (nothing
 * pricing-shaped exists yet — separately tracked), security/audit logs
 * (AuditLog test file), or sensitive records (financial data, compliance
 * info) — this file covers the compliance/sensitive-record half of that:
 * a firm's legal-entity fields, its suspend toggle, and its named user list.
 *
 * Tests the persisted result of a real fillForm()+save(), not just what
 * renders — that's the actual security boundary (can Sales make the change
 * stick), not merely a UI nicety.
 *
 * setUp() primes spatie's permissions team id the same way
 * SetHubPermissionsTeam middleware does for a real request — that
 * middleware never runs under Livewire::test() (it bypasses the HTTP
 * middleware stack entirely), so TenantResource's plain auth()->user()->
 * can(...) checks would otherwise evaluate under the wrong team scope and
 * 403 the mount. HubPanelAccessTest/HubForcedPasswordChangeTest never hit
 * this because they drive everything through real $this->get()/post() HTTP
 * requests, which do run the full middleware stack; this file is the first
 * to exercise a Hub page's fillForm()/save() directly.
 */
class HubSalesDirectorSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('hub'));
        app(PermissionRegistrar::class)->setPermissionsTeamId(HubAccess::TEAM_ID);
    }

    private static int $tenantSequence = 0;

    private function hubUser(string $role): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        return $user;
    }

    private function tenant(): Tenant
    {
        $n = ++self::$tenantSequence;

        return Tenant::create([
            'name' => "Test Firm {$n}",
            'slug' => "test-firm-{$n}",
            'reference_prefix' => "TF{$n}",
            'is_active' => true,
        ]);
    }

    private function mountEditTenant(Tenant $tenant)
    {
        return Livewire::test(EditTenant::class, ['record' => $tenant->getKey()]);
    }

    public function test_sales_cannot_persist_a_change_to_the_legal_entity_compliance_fields(): void
    {
        $sales = $this->hubUser(HubAccess::ROLE_SALES);
        $this->actingAs($sales);
        $tenant = $this->tenant();

        $this->mountEditTenant($tenant)
            ->fillForm([
                'tagline' => 'Still allowed',
                'sra_number' => 'SHOULD-NOT-PERSIST',
                'company_number' => 'SHOULD-NOT-PERSIST',
                'legal_entity_name' => 'SHOULD-NOT-PERSIST',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $tenant->refresh();
        $this->assertSame('Still allowed', $tenant->tagline);
        $this->assertNull($tenant->sra_number);
        $this->assertNull($tenant->company_number);
        $this->assertNull($tenant->legal_entity_name);
    }

    public function test_director_can_persist_a_change_to_the_legal_entity_compliance_fields(): void
    {
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $this->actingAs($director);
        $tenant = $this->tenant();

        $this->mountEditTenant($tenant)
            ->fillForm(['sra_number' => '123456'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('123456', $tenant->fresh()->sra_number);
    }

    public function test_sales_cannot_persist_suspending_a_firm(): void
    {
        $sales = $this->hubUser(HubAccess::ROLE_SALES);
        $this->actingAs($sales);
        $tenant = $this->tenant();

        $this->assertTrue($tenant->is_active);

        $this->mountEditTenant($tenant)
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($tenant->fresh()->is_active);
    }

    public function test_director_can_suspend_a_firm(): void
    {
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $this->actingAs($director);
        $tenant = $this->tenant();

        $this->mountEditTenant($tenant)
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($tenant->fresh()->is_active);
    }

    public function test_sales_cannot_view_a_firms_named_user_list(): void
    {
        $sales = $this->hubUser(HubAccess::ROLE_SALES);
        $this->actingAs($sales);
        $tenant = $this->tenant();

        $this->assertFalse(UsersRelationManager::canViewForRecord($tenant, EditTenant::class));
    }

    public function test_director_can_view_a_firms_named_user_list(): void
    {
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $this->actingAs($director);
        $tenant = $this->tenant();

        $this->assertTrue(UsersRelationManager::canViewForRecord($tenant, EditTenant::class));
    }

    public function test_both_roles_can_still_create_and_do_routine_edits(): void
    {
        foreach ([HubAccess::ROLE_SALES, HubAccess::ROLE_DIRECTOR] as $role) {
            $user = $this->hubUser($role);
            $this->actingAs($user);
            $tenant = $this->tenant();

            $this->mountEditTenant($tenant)
                ->fillForm(['tagline' => "Updated by {$role}"])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame("Updated by {$role}", $tenant->fresh()->tagline);
        }
    }
}
