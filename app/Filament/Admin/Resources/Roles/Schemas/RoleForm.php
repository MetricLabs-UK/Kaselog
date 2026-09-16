<?php

namespace App\Filament\Admin\Resources\Roles\Schemas;

use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role')
                ->components([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: 'roles',
                            column: 'name',
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', CurrentTenant::id())->where('guard_name', 'web'),
                        )
                        ->helperText('Renaming a role does not affect who holds it.'),
                ]),

            // One Section + CheckboxList per category rather than a single
            // flat list — purely a display grouping (see
            // RolePermissionCatalog's own docblock); each category writes
            // to its own virtual permissions_{category} field, merged back
            // into one list for syncPermissions() in
            // CreateRole/EditRole — see mutatePermissionCategoriesIntoData()/
            // splitPermissionsIntoCategories() there.
            ...collect(RolePermissionCatalog::grouped())
                ->map(fn (array $options, string $category) => Section::make($category)
                    ->components([
                        CheckboxList::make('permissions_'.str($category)->slug('_'))
                            ->hiddenLabel()
                            ->options($options)
                            ->columns(2)
                            ->gridDirection('row'),
                    ]))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Fully editable, no protected baseline (Section 18 item 2's spec) — a
     * firm can rename, edit, or delete any role including the seeded
     * defaults. spatie's own pivot tables cascade-delete on role removal
     * (config/permission.php's migration), so nothing orphans; a user
     * holding a deleted role simply loses it, same as any other permission
     * change here.
     */
    public static function seededRoleNames(): array
    {
        return array_keys(TenantRoleSeeder::ROLE_GRANTS);
    }

    public static function fillPermissionCategories(Role $role): array
    {
        $current = $role->permissions->pluck('name')->all();

        return collect(RolePermissionCatalog::grouped())
            ->mapWithKeys(fn (array $options, string $category) => [
                'permissions_'.str($category)->slug('_') => array_values(array_intersect(array_keys($options), $current)),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function mergePermissionCategories(array $data): array
    {
        $keys = collect(RolePermissionCatalog::grouped())
            ->keys()
            ->map(fn (string $category): string => 'permissions_'.str($category)->slug('_'));

        return $keys
            ->flatMap(fn (string $key): array => $data[$key] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function permissionCategoryKeys(): array
    {
        return collect(RolePermissionCatalog::grouped())
            ->keys()
            ->map(fn (string $category): string => 'permissions_'.str($category)->slug('_'))
            ->all();
    }

    /**
     * Deleting a Role fires no spatie event of its own (RoleAttached/
     * DetachedEvent are for a *model* gaining/losing a role, not the role
     * record itself going away) — logged explicitly here instead, called
     * from both RolesTable's and EditRole's DeleteAction.
     */
    public static function logRoleDeleted(Role $role): void
    {
        activity('permissions')
            ->causedBy(auth()->user())
            ->performedOn($role)
            ->withProperties(['name' => $role->name])
            ->tap(fn ($activity) => $activity->tenant_id = $role->tenant_id)
            ->event('role_deleted')
            ->log("Role \"{$role->name}\" deleted");
    }
}
