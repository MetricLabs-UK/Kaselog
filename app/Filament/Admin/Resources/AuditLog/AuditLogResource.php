<?php

namespace App\Filament\Admin\Resources\AuditLog;

use App\Filament\Admin\Resources\AuditLog\Pages\ListAuditLog;
use App\Filament\Admin\Resources\AuditLog\Tables\AuditLogTable;
use App\Models\Activity;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only browser over Spatie's activity log. Director-only, tabbed by
 * audited category. See ListAuditLog::getTabs() to extend to a new resource.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Audit Log';

    protected static ?string $navigationLabel = 'Change Log';

    /**
     * Reached via the sidebar user menu (AdminPanelProvider) instead, to
     * keep the main nav focused on day-to-day work.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'change log entry';

    protected static ?string $pluralModelLabel = 'change log';

    /**
     * Spatie's Activity model has no tenant() relationship for Filament's
     * own automatic tenant-ownership scoping to use (it's a package model,
     * not one of ours) — that mismatch throws a LogicException the moment
     * this page loads. activity_log does have a tenant_id column (added
     * alongside every other scoped table), so this resource scopes itself
     * manually via getEloquentQuery() instead, fail-closed to match
     * App\Models\Scopes\TenantScope's own convention.
     */
    protected static bool $isScopedToTenant = false;

    public static function table(Table $table): Table
    {
        return AuditLogTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        // CurrentTenant, not Filament::getTenant() directly — the one
        // tenant-resolution order app-wide (an explicit set() wins, panel
        // tenant as fallback), same as TenantScope. Audit finding F3.
        $tenantId = CurrentTenant::id();

        if ($tenantId === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->where('tenant_id', $tenantId);
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('view_audit_log');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLog::route('/'),
        ];
    }
}
