<?php

namespace App\Filament\Admin\Resources\Matters;

use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterDocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TimeEntryRelationManager;
use App\Filament\Admin\Resources\Matters\Schemas\MatterForm;
use App\Filament\Admin\Resources\Matters\Schemas\MatterInfolist;
use App\Filament\Admin\Resources\Matters\Tables\MattersTable;
use App\Models\Matter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MatterResource extends Resource
{
    protected static ?string $model = Matter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Clients & Matters';

    public static function form(Schema $schema): Schema
    {
        return MatterForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MatterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MattersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if (! $user->can('view_confidential_records')) {
            // Both the matter's own flag AND the client's: a director_only
            // client's matters are confidential wholesale, not just the
            // client record (audit finding F20). Filtering here (not only
            // in canView) means Filament can't even resolve such a record
            // for a non-director — direct URLs 404, same as list views.
            $query->where('director_only', false)
                ->whereHas('client', fn (Builder $clientQuery) => $clientQuery->where('director_only', false));
        }

        // Solicitors see all matters by default. When SCOPE_SOLICITORS is
        // enabled, restrict them to matters assigned to them. A feature flag,
        // not a confidentiality boundary — stays a literal role check.
        if ($user->hasRole('solicitor') && config('kaselog.scope_solicitors')) {
            $query->where('assigned_user_id', $user->id);
        }

        return $query;
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('view_matters');
    }

    public static function canView(Model $record): bool
    {
        if ($record->director_only || $record->client?->director_only) {
            return auth()->user()->can('view_confidential_records');
        }

        return true;
    }

    public static function canEdit(Model $record): bool
    {
        if ($record->locked) {
            return auth()->user()->can('manage_locked_records');
        }

        return auth()->user()->can('edit_matters');
    }

    /**
     * True hard-delete, director-only regardless of locked state — Archive
     * (App\Filament\Support\ArchiveActions, gated on archive_matters) is the
     * normal removal path now; this is deliberately a separate, narrower
     * permission (force_delete_records) than the delete_matters permission
     * still used elsewhere for child-record deletion within a Matter.
     */
    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('force_delete_records');
    }

    public static function getRelations(): array
    {
        return [
            TimeEntryRelationManager::class,
            MatterDocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatters::route('/'),
            'create' => CreateMatter::route('/create'),
            'view' => ViewMatter::route('/{record}'),
            'edit' => EditMatter::route('/{record}/edit'),
        ];
    }
}
