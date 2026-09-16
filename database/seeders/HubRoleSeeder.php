<?php

namespace Database\Seeders;

use App\Support\Hub\HubAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the two fixed Hub-level roles under HubAccess::TEAM_ID. Unlike
 * TenantRoleSeeder's per-firm roles (fully editable, no protected baseline —
 * see the Hub role-management UI), these two are Kase's own back-office
 * roles and aren't user-editable.
 *
 * Idempotent — syncPermissions() replaces rather than accumulates, safe to
 * re-run. Extend ROLE_GRANTS as more Hub permissions are added (pricing,
 * security-log visibility, cross-tenant access, ...).
 */
class HubRoleSeeder extends Seeder
{
    private const GUARD = 'web';

    /**
     * @var array<string, array<int, string>>
     */
    public const ROLE_GRANTS = [
        HubAccess::ROLE_SALES => [
            HubAccess::PERMISSION_ACCESS,
            HubAccess::PERMISSION_VIEW_FIRMS,
            HubAccess::PERMISSION_MANAGE_FIRMS,
            HubAccess::PERMISSION_MANAGE_TEMPLATE_LIBRARY,
        ],
        HubAccess::ROLE_DIRECTOR => [
            HubAccess::PERMISSION_ACCESS,
            HubAccess::PERMISSION_VIEW_FIRMS,
            HubAccess::PERMISSION_MANAGE_FIRMS,
            HubAccess::PERMISSION_MANAGE_FIRM_COMPLIANCE,
            HubAccess::PERMISSION_SUSPEND_FIRMS,
            HubAccess::PERMISSION_VIEW_FIRM_USERS,
            HubAccess::PERMISSION_IMPERSONATE,
            HubAccess::PERMISSION_VIEW_AUDIT_LOG,
            HubAccess::PERMISSION_PHONE_LOOKUP,
            HubAccess::PERMISSION_MANAGE_BILLING,
            HubAccess::PERMISSION_MANAGE_TEMPLATE_LIBRARY,
        ],
    ];

    public static function ensurePermissionsExist(): void
    {
        $allPermissions = array_unique(array_merge(...array_values(self::ROLE_GRANTS)));

        foreach ($allPermissions as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }
    }

    public static function seed(): void
    {
        self::ensurePermissionsExist();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLE_GRANTS as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate([
                'tenant_id' => HubAccess::TEAM_ID,
                'name' => $roleName,
                'guard_name' => self::GUARD,
            ]);

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function run(): void
    {
        self::seed();
    }
}
