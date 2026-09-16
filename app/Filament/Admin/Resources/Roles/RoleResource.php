<?php

namespace App\Filament\Admin\Resources\Roles;

use App\Filament\Admin\Resources\Roles\Pages\CreateRole;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Roles\Schemas\RoleForm;
use App\Filament\Admin\Resources\Roles\Tables\RolesTable;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Section 18 item 2 — per-tenant role management, director-only
 * (manage_roles). Fully editable, no protected baseline: a firm can rename,
 * edit, or delete any role including the four Kaselog seeds itself
 * (director/admin/solicitor/accounts) — see RoleForm's own docblock.
 *
 * Role is spatie's own model, not one of ours, so — same as
 * AuditLogResource — it has no tenant() relationship for Filament's
 * automatic tenant-ownership scoping to use; scoped manually below instead,
 * fail-closed to match App\Models\Scopes\TenantScope's own convention.
 *
 * Reached via the sidebar user menu (AdminPanelProvider), like Precedent
 * Templates/Time Entries/Audit Log, to keep the main nav focused on
 * day-to-day work.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = 'Roles & Permissions';

    protected static bool $shouldRegisterNavigation = false;

    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = CurrentTenant::id();

        if ($tenantId === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('tenant_id', $tenantId)
            ->where('guard_name', 'web');
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('manage_roles');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('manage_roles');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('manage_roles');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('manage_roles');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
