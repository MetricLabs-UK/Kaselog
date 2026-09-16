<?php

namespace App\Filament\Admin\Resources\Leads;

use App\Filament\Admin\Resources\Leads\Pages\CreateLead;
use App\Filament\Admin\Resources\Leads\Pages\EditLead;
use App\Filament\Admin\Resources\Leads\Pages\ListLeads;
use App\Filament\Admin\Resources\Leads\Pages\ViewLead;
use App\Filament\Admin\Resources\Leads\RelationManagers\NurtureSequencesRelationManager;
use App\Filament\Admin\Resources\Leads\Schemas\LeadForm;
use App\Filament\Admin\Resources\Leads\Schemas\LeadInfolist;
use App\Filament\Admin\Resources\Leads\Tables\LeadsTable;
use App\Models\Lead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()->can('view_confidential_records')) {
            $query->where('director_only', false);
        }

        return $query;
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('view_leads');
    }

    public static function canView(Model $record): bool
    {
        if ($record->director_only) {
            return auth()->user()->can('view_confidential_records');
        }

        return true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('create_leads');
    }

    public static function canEdit(Model $record): bool
    {
        if ($record->locked) {
            return auth()->user()->can('manage_locked_records');
        }

        return auth()->user()->can('edit_leads');
    }

    /**
     * True hard-delete, director-only — Archive (App\Filament\Support\
     * ArchiveActions, gated on archive_leads) is the normal removal path
     * now; force_delete_records is a separate, narrower permission than
     * delete_leads, which still governs child-record deletion within a Lead
     * (see NurtureSequencesRelationManager).
     */
    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('force_delete_records');
    }

    public static function getRelations(): array
    {
        return [
            NurtureSequencesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'view' => ViewLead::route('/{record}'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }
}
