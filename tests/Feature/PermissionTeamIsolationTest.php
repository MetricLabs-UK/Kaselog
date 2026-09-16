<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fundamental guarantee behind the spatie/laravel-permission teams migration:
 * two tenants can each define a role with the same name and different
 * permissions, and a user's grants in one tenant never leak into or get
 * confused with another tenant's identically-named role.
 */
class PermissionTeamIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_named_roles_in_different_tenants_are_isolated(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'reference_prefix' => 'TA']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'reference_prefix' => 'TB']);

        $roleA = Role::create(['name' => 'Special', 'guard_name' => 'web', 'tenant_id' => $tenantA->id]);
        $roleA->givePermissionTo(Permission::findOrCreate('secret_permission_a', 'web'));

        // Deliberately not given secret_permission_a.
        Role::create(['name' => 'Special', 'guard_name' => 'web', 'tenant_id' => $tenantB->id]);

        $user = User::factory()->create();
        $user->tenants()->attach([$tenantA->id, $tenantB->id]);

        CurrentTenant::set($tenantA);
        $user->assignRole('Special');
        $this->assertTrue($user->fresh()->can('secret_permission_a'));

        CurrentTenant::set($tenantB);
        $user->assignRole('Special'); // same NAME, different tenant's row
        $this->assertFalse(
            $user->fresh()->can('secret_permission_a'),
            'Tenant B\'s "Special" role must not inherit Tenant A\'s permissions.',
        );

        CurrentTenant::set($tenantA);
        $this->assertTrue(
            $user->fresh()->can('secret_permission_a'),
            'Switching back to Tenant A must restore its own grant.',
        );

        CurrentTenant::clear();
    }
}
