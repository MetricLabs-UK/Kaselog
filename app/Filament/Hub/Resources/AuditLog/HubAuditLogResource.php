<?php

namespace App\Filament\Hub\Resources\AuditLog;

use App\Filament\Hub\Resources\AuditLog\Pages\ListHubAuditLog;
use App\Filament\Hub\Resources\AuditLog\Tables\HubAuditLogTable;
use App\Models\Activity;
use App\Support\Hub\HubAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Section 18 item 3 — director-only (Sales must not see security/audit
 * logs). Read-only browser over the same activity_log Spatie table every
 * other audit view uses, scoped to what's actually Hub-relevant rather than
 * every firm's own routine activity:
 * - impersonation_sessions: always Hub-relevant (Section 19's full
 *   lifecycle — request/accept/decline/start/end).
 * - auth / access_denied: only the rows stamped with properties.panel =
 *   'hub' (a firm's own logins/denials already surface in that firm's own
 *   AuditLogResource, tenant-scoped — showing them here too would just be
 *   noise since Hub looks across every firm at once).
 * - cross_tenant_attempt: always shown regardless of tenant_id (it's
 *   stamped to the *attempted* tenant, but the event itself is exactly the
 *   kind of cross-firm security signal Hub oversight exists for).
 * - permissions: only Hub-team rows (hub_director/hub_sales role or
 *   permission changes) — tenant_id normalises to null for those, see
 *   LogPermissionActivity::resolveTenantId().
 *
 * Same $isScopedToTenant = false rationale as the tenant-side
 * AuditLogResource — Activity has no tenant() relationship for Filament's
 * automatic scoping to use, and this resource deliberately scopes itself
 * manually below instead, independent of Filament's per-panel tenancy.
 */
class HubAuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    // Without this, Filament auto-derives hub/audit-log/hub-audit-logs
    // (the AuditLog subdirectory's own slug, plus the class-name-derived
    // one) — this resource is the only thing in that subdirectory, so a
    // flat slug reads better.
    protected static ?string $slug = 'audit-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Firms';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $modelLabel = 'audit log entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static bool $isScopedToTenant = false;

    public static function table(Table $table): Table
    {
        return HubAuditLogTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where(function (Builder $query): void {
            $query
                ->where('log_name', 'impersonation_sessions')
                ->orWhere(fn (Builder $q) => $q->whereIn('log_name', ['auth', 'access_denied'])->where('properties->panel', 'hub'))
                ->orWhere('event', 'cross_tenant_attempt')
                ->orWhere(fn (Builder $q) => $q->where('log_name', 'permissions')->whereNull('tenant_id'));
        });
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasHubPermission(HubAccess::PERMISSION_VIEW_AUDIT_LOG);
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
            'index' => ListHubAuditLog::route('/'),
        ];
    }
}
