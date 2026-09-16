<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use App\Support\Hub\HubAccess;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\HubRoleSeeder;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * config('permission.events_enabled') drives all of this — spatie fires
 * RoleAttached/DetachedEvent and PermissionAttached/DetachedEvent, which
 * App\Listeners\LogPermissionActivity reacts to. Covers both directions:
 * a user being granted/revoked a role, and a role having its own
 * permissions edited (what the future per-tenant role-management UI will
 * do) — both firm-level (real tenant_id) and Hub-level (hub_* roles under
 * HubAccess::TEAM_ID, which normalises to a null tenant_id).
 */
class PermissionActivityLoggingTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_granting_a_firm_role_to_a_user_is_logged_with_the_causer_and_tenant(): void
    {
        $this->setUpTenant();
        TenantRoleSeeder::seed($this->tenant);
        CurrentTenant::set($this->tenant);

        $director = User::factory()->create();
        $director->assignRole('director');
        $this->actingAs($director);

        $solicitor = User::factory()->create();
        $solicitor->assignRole('solicitor');

        $activity = Activity::query()->inLog('permissions')->forEvent('role_attached')->forSubject($solicitor)->sole();

        $this->assertSame($director->id, $activity->causer_id);
        $this->assertSame($this->tenant->id, $activity->tenant_id);
        $this->assertSame(['solicitor'], $activity->getExtraProperty('names'));
    }

    public function test_revoking_a_firm_role_from_a_user_is_logged(): void
    {
        $this->setUpTenant();
        TenantRoleSeeder::seed($this->tenant);
        CurrentTenant::set($this->tenant);

        $director = User::factory()->create();
        $director->assignRole('director');
        $this->actingAs($director);

        $solicitor = User::factory()->create();
        $solicitor->assignRole('solicitor');
        $solicitor->removeRole('solicitor');

        $activity = Activity::query()->inLog('permissions')->forEvent('role_detached')->forSubject($solicitor)->sole();

        $this->assertSame(['solicitor'], $activity->getExtraProperty('names'));
    }

    public function test_editing_a_roles_permissions_is_logged_against_the_role_with_the_tenant(): void
    {
        $this->setUpTenant();
        TenantRoleSeeder::seed($this->tenant);
        CurrentTenant::set($this->tenant);

        $director = User::factory()->create();
        $director->assignRole('director');
        $this->actingAs($director);

        $role = Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'solicitor')->firstOrFail();
        $role->givePermissionTo('delete_matters');

        // TenantRoleSeeder::seed() above already fired its own
        // permission_attached row for this same role (seeding its default
        // grants) — the latest row is the one this test just caused.
        $activity = Activity::query()->inLog('permissions')->forEvent('permission_attached')->forSubject($role)->latest('id')->firstOrFail();

        $this->assertSame($director->id, $activity->causer_id);
        $this->assertSame($this->tenant->id, $activity->tenant_id);
        $this->assertContains('delete_matters', $activity->getExtraProperty('names'));
    }

    public function test_hub_role_grants_are_logged_without_a_real_tenant_id(): void
    {
        HubRoleSeeder::seed();

        $director = User::factory()->create();
        $this->actingAs($director);

        $sales = User::factory()->create();
        HubAccess::withHubTeam(fn () => $sales->assignRole(HubAccess::ROLE_SALES));

        $activity = Activity::query()->inLog('permissions')->forEvent('role_attached')->forSubject($sales)->sole();

        $this->assertSame($director->id, $activity->causer_id);
        $this->assertNull($activity->tenant_id);
        $this->assertSame([HubAccess::ROLE_SALES], $activity->getExtraProperty('names'));
    }
}
