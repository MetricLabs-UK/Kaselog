<?php

namespace Tests\Unit;

use App\Filament\Admin\Resources\Roles\Schemas\RolePermissionCatalog;
use Database\Seeders\TenantRoleSeeder;
use Tests\TestCase;

/**
 * Mirrors ActivityLogTenantStampingTest's role: fails the build if
 * RolePermissionCatalog (the management UI's checklist) and
 * TenantRoleSeeder::ROLE_GRANTS (the actual default grant matrix) drift
 * apart — both list permission names by hand, in two different shapes, with
 * no shared source of truth enforcing they agree.
 */
class RolePermissionCatalogTest extends TestCase
{
    public function test_the_permission_catalog_matches_every_permission_used_in_the_default_role_grants(): void
    {
        $grantedPermissions = collect(TenantRoleSeeder::ROLE_GRANTS)
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $cataloguedPermissions = collect(RolePermissionCatalog::allPermissionNames())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame($grantedPermissions, $cataloguedPermissions);
    }

    public function test_the_catalog_has_no_duplicate_permission_names_across_categories(): void
    {
        $all = RolePermissionCatalog::allPermissionNames();

        $this->assertCount(count($all), array_unique($all));
    }
}
