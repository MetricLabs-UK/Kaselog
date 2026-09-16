<?php

namespace App\Filament\Hub\Resources\AuditLog\Pages;

use App\Filament\Hub\Resources\AuditLog\HubAuditLogResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListHubAuditLog extends ListRecords
{
    protected static string $resource = HubAuditLogResource::class;

    /**
     * One tab per Hub-relevant category — add a new entry here whenever
     * another Hub-scoped event starts logging (matching its log_name),
     * mirroring the tenant-side ListAuditLog's own convention.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'access_denied' => Tab::make('Access Denials')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('access_denied')),
            'impersonation' => Tab::make('Impersonation')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('impersonation_sessions')),
            'auth' => Tab::make('Logins')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('auth')),
            'permissions' => Tab::make('Roles & Permissions')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inLog('permissions')),
        ];
    }
}
