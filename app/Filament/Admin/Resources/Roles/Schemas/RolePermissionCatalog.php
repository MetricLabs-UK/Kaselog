<?php

namespace App\Filament\Admin\Resources\Roles\Schemas;

/**
 * The permission checklist shown on the Role form, grouped for readability
 * — purely a display concern, so it's kept separate from TenantRoleSeeder::
 * ROLE_GRANTS (the actual default role→permission matrix, shaped
 * role => [permissions] rather than category => [permission => label]).
 *
 * Permissions themselves are a fixed, global catalog (config/permission.php:
 * the permissions table has no tenant scoping at all, unlike roles) — each
 * one corresponds to a specific auth()->user()->can(...) check hardcoded
 * into a Resource class somewhere in this app. A firm can freely create,
 * rename, or delete *roles* and reassign which of these existing
 * permissions each one holds, but can't invent a new permission through
 * this UI — one with no matching code check would do nothing, and the
 * permissions table itself isn't tenant-scoped, so creating one here would
 * pollute the catalog every other firm sees too.
 *
 * ActivityLogTenantStampingTest-style invariant enforced by
 * RolePermissionCatalogTest: this list must contain exactly the same
 * permission names as TenantRoleSeeder::ROLE_GRANTS, so a newly added
 * permission can't silently go missing from the management UI (or vice
 * versa).
 */
class RolePermissionCatalog
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        return [
            'Matters' => [
                'view_matters' => 'View matters',
                'edit_matters' => 'Edit matters',
                'delete_matters' => 'Delete child records (documents, etc.)',
                'archive_matters' => 'Archive / restore matters',
            ],
            'Clients' => [
                'view_clients' => 'View clients',
                'edit_clients' => 'Edit clients',
                'delete_clients' => 'Delete child records',
                'archive_clients' => 'Archive / restore clients',
            ],
            'Leads' => [
                'view_leads' => 'View leads',
                'create_leads' => 'Create leads',
                'edit_leads' => 'Edit leads',
                'delete_leads' => 'Delete child records',
                'archive_leads' => 'Archive / restore leads',
            ],
            'Finance' => [
                'view_finance' => 'View payment plans',
                'edit_payment_plans' => 'Edit payment plans',
                'delete_payment_plans' => 'Delete instalments',
                'archive_payment_plans' => 'Archive / restore payment plans',
            ],
            'Time Entries' => [
                'create_time_entries' => 'Create time entries',
                'edit_own_time_entry' => 'Edit own time entries',
                'edit_any_time_entry' => "Edit anyone's time entries",
                'delete_time_entries' => 'Delete time entries',
                'manage_invoices' => 'Bundle time entries into invoices and send to accounting',
            ],
            'Precedent Templates' => [
                'view_precedent_templates' => 'View precedent templates',
                'create_precedent_templates' => 'Create precedent templates',
                'edit_precedent_templates' => 'Edit precedent templates',
                'delete_precedent_templates' => 'Delete precedent templates',
            ],
            'Calls' => [
                'view_call_notes' => 'View call notes',
                'review_call_notes' => 'Mark flagged calls as reviewed',
            ],
            'Compliance & Oversight' => [
                'view_audit_log' => 'View the audit log',
                'view_confidential_records' => 'View director-only confidential records',
                'manage_locked_records' => 'Edit locked records',
                'force_delete_records' => 'Permanently (hard) delete records',
                'manage_roles' => 'Manage roles & permissions',
                'manage_integrations' => 'Manage integrations (Settings > Integrations)',
            ],
            'Backups' => [
                'manage_backups' => 'Request client/firm backups and manage backup destinations (Settings > Integrations)',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allPermissionNames(): array
    {
        return array_merge(...array_values(array_map(
            fn (array $group): array => array_keys($group),
            self::grouped(),
        )));
    }

    /**
     * @return array<int, string>
     */
    public static function categoryKeys(): array
    {
        return array_map(
            fn (string $category): string => 'permissions_'.str($category)->slug('_')->toString(),
            array_keys(self::grouped()),
        );
    }
}
