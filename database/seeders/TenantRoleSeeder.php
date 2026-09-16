<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The canonical director/admin/solicitor/accounts role→permission grant
 * matrix, reproducing the old UserRole-enum behaviour exactly. Reused by:
 * - Tenant::booted()'s created hook, for every future tenant.
 * - The one-off backfill for tenants that already existed before this
 *   package (MigrateUserRolesToPermissions), and DatabaseSeeder for a fresh
 *   install's own director user.
 */
class TenantRoleSeeder extends Seeder
{
    private const GUARD = 'web';

    /**
     * delete_matters/delete_clients/delete_leads/delete_payment_plans keep
     * their original meaning: gating deletion of *child* records within one
     * of these (an uploaded document, a nurture-sequence log entry, an
     * instalment — see the relevant RelationManagers), unrelated to Section
     * 13's archive system. archive_* is the new, separate permission for
     * archiving the top-level record itself (Matter/Client/Lead/PaymentPlan
     * Resources' own Archive/Restore actions — App\Filament\Support\
     * ArchiveActions); force_delete_records gates the equivalent top-level
     * *hard*-delete action, now director-only regardless of who held
     * delete_matters etc. before — see those Resources' canDelete().
     *
     * manage_roles (Section 18 item 2) gates the per-tenant Role
     * management UI itself — director-only, deliberately not grantable
     * away via that same UI (App\Filament\Admin\Resources\Roles\
     * RoleResource checks it directly via auth()->user()->can(), not by
     * looking the permission up dynamically, so a director can't
     * accidentally remove the only route back into this screen).
     *
     * manage_integrations (Section 20) gates Settings > Integrations —
     * director-only: connecting/disconnecting a firm's accounting software
     * (and everything that comes with it — live OAuth tokens, which contact
     * records get created) is not routine day-to-day work.
     *
     * manage_invoices (Section 6) gates bundling time entries into an
     * invoice and sending it to the accounting provider — unlike
     * manage_integrations, this is routine billing work, so both accounts
     * and director hold it.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_GRANTS = [
        'director' => [
            'view_matters', 'edit_matters', 'delete_matters', 'archive_matters',
            'view_clients', 'edit_clients', 'delete_clients', 'archive_clients',
            'view_leads', 'create_leads', 'edit_leads', 'delete_leads', 'archive_leads',
            'view_finance', 'edit_payment_plans', 'delete_payment_plans', 'archive_payment_plans',
            'create_time_entries', 'edit_any_time_entry', 'delete_time_entries', 'manage_invoices',
            'view_precedent_templates', 'create_precedent_templates', 'edit_precedent_templates', 'delete_precedent_templates',
            'view_audit_log',
            'view_confidential_records', 'manage_locked_records',
            'force_delete_records',
            'view_call_notes', 'review_call_notes',
            'manage_roles',
            'manage_integrations',
            'manage_backups',
        ],
        'admin' => [
            'view_matters', 'edit_matters', 'delete_matters', 'archive_matters',
            'view_clients', 'edit_clients', 'delete_clients', 'archive_clients',
            'view_leads', 'create_leads', 'edit_leads',
            'create_time_entries', 'edit_own_time_entry',
            'view_precedent_templates', 'create_precedent_templates', 'edit_precedent_templates',
            'view_call_notes', 'review_call_notes',
        ],
        'solicitor' => [
            'view_matters',
            'view_clients',
            'view_leads',
            'create_time_entries', 'edit_own_time_entry',
            'view_precedent_templates', 'create_precedent_templates', 'edit_precedent_templates',
        ],
        'accounts' => [
            'view_finance', 'edit_payment_plans', 'delete_payment_plans', 'archive_payment_plans',
            'manage_invoices',
        ],
    ];

    public static function ensurePermissionsExist(): void
    {
        $allPermissions = array_unique(array_merge(...array_values(self::ROLE_GRANTS)));

        foreach ($allPermissions as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }
    }

    /**
     * Idempotent and self-contained — doesn't need CurrentTenant primed:
     * tenant_id is passed explicitly to firstOrCreate() rather than relying
     * on spatie's own Role::create()/findOrCreate() statics (which read the
     * ambient team id when tenant_id isn't in the attributes), and
     * syncPermissions() on a Role never touches a team pivot at all.
     *
     * Explicitly flushes spatie's permission cache rather than relying on its
     * usual Role/Permission "saved" event hook — DatabaseSeeder (one of this
     * method's callers) uses WithoutModelEvents, which silently suppresses
     * that hook and would otherwise leave can()/syncPermissions() reading a
     * stale, pre-seed cache for the rest of the process.
     */
    public static function seed(Tenant $tenant): void
    {
        self::ensurePermissionsExist();

        // Must happen before syncPermissions() below, not just at the end:
        // syncPermissions() resolves each permission name via the registrar's
        // own cached permission list, so a stale cache here would make it
        // fail to find permissions ensurePermissionsExist() just created.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLE_GRANTS as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'name' => $roleName,
                'guard_name' => self::GUARD,
            ]);

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function run(): void
    {
        Tenant::all()->each(fn (Tenant $tenant) => self::seed($tenant));
    }
}
