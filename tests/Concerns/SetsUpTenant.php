<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;

/**
 * Every scoped model requires a resolvable tenant to create or query
 * successfully. Tests that don't exercise tenancy directly still need one
 * active so their existing fixture setup keeps working unchanged.
 */
trait SetsUpTenant
{
    protected Tenant $tenant;

    protected function setUpTenant(array $attributes = []): Tenant
    {
        // The tenant-seeding migration (2026_08_03_120003) already creates
        // Lostock Legal as part of RefreshDatabase's migration run — reuse
        // it rather than colliding on the unique slug/reference_prefix.
        $this->tenant = Tenant::firstOrCreate(
            ['slug' => 'lostock-legal'],
            ['name' => 'Lostock Legal', 'reference_prefix' => 'LL'],
        );

        if ($attributes !== []) {
            $this->tenant->update($attributes);
        }

        CurrentTenant::set($this->tenant);

        return $this->tenant;
    }

    /**
     * Creates a plain User, attaches them to $tenant (default: the tenant
     * from setUpTenant()), assigns them the given spatie role scoped to that
     * tenant, and acts as them. Replaces the old
     * `User::factory()->create(['role' => UserRole::X])` pattern now that
     * authorization reads spatie roles, not the legacy enum column.
     */
    protected function actingAsRole(string $roleName, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;

        TenantRoleSeeder::seed($tenant);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant);

        CurrentTenant::set($tenant);
        $user->assignRole($roleName);

        $this->actingAs($user);

        return $user;
    }
}
