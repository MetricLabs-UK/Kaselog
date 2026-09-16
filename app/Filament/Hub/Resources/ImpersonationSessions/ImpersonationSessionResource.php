<?php

namespace App\Filament\Hub\Resources\ImpersonationSessions;

use App\Filament\Hub\Resources\ImpersonationSessions\Pages\ListImpersonationSessions;
use App\Filament\Hub\Resources\ImpersonationSessions\Tables\ImpersonationSessionsTable;
use App\Models\ImpersonationSession;
use App\Support\Hub\HubAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Requests are created from UsersRelationManager (on TenantResource), never
 * from a form here — this is purely the "what am I waiting on / what can I
 * enter" operational view, scoped to the current director's own requests.
 * Cross-director oversight of all impersonation activity lives in the audit
 * trail (activity_log), not here — Hub has no cross-firm audit log viewer
 * yet (Section 14's is tenant-scoped), which is a real gap worth its own
 * follow-up, not something folded into this pass.
 */
class ImpersonationSessionResource extends Resource
{
    protected static ?string $model = ImpersonationSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $navigationLabel = 'Impersonation';

    protected static string|UnitEnum|null $navigationGroup = 'Firms';

    public static function table(Table $table): Table
    {
        return ImpersonationSessionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('requested_by', auth()->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasHubPermission(HubAccess::PERMISSION_IMPERSONATE);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImpersonationSessions::route('/'),
        ];
    }
}
