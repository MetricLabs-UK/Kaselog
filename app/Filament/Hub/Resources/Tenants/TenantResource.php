<?php

namespace App\Filament\Hub\Resources\Tenants;

use App\Filament\Hub\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Hub\Resources\Tenants\Pages\EditTenant;
use App\Filament\Hub\Resources\Tenants\Pages\ListTenants;
use App\Filament\Hub\Resources\Tenants\Pages\ManageTenantBilling;
use App\Filament\Hub\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Filament\Hub\Resources\Tenants\Schemas\TenantForm;
use App\Filament\Hub\Resources\Tenants\Tables\TenantsTable;
use App\Models\Tenant;
use App\Support\Hub\HubAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * There is no per-tenant scoping here — the Hub deliberately looks across
 * every firm at once (see HubPanelProvider's docblock). Deleting a firm
 * isn't offered; suspending it via is_active (EnsureTenantIsActive) is the
 * supported way to cut off its access.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Firms';

    protected static string|UnitEnum|null $navigationGroup = 'Firms';

    public static function form(Schema $schema): Schema
    {
        return TenantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can(HubAccess::PERMISSION_VIEW_FIRMS);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can(HubAccess::PERMISSION_MANAGE_FIRMS);
    }

    /**
     * Gates reaching the edit page at all, not every field on it — the
     * compliance section and the is_active toggle are separately gated
     * inside TenantForm to PERMISSION_MANAGE_FIRM_COMPLIANCE/
     * PERMISSION_SUSPEND_FIRMS (director-only), since Sales legitimately
     * needs this page for routine edits (name, tagline, logo, parent).
     */
    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can(HubAccess::PERMISSION_MANAGE_FIRMS);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
            'billing' => ManageTenantBilling::route('/{record}/billing'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }
}
