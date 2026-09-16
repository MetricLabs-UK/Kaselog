<?php

namespace App\Filament\Admin\Resources\Clients;

use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\BackupExportsRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\MattersRelationManager;
use App\Filament\Admin\Resources\Clients\Schemas\ClientForm;
use App\Filament\Admin\Resources\Clients\Schemas\ClientInfolist;
use App\Filament\Admin\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Clients & Matters';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClientInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
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
        return auth()->user()->can('view_clients');
    }

    public static function canView(Model $record): bool
    {
        if ($record->director_only) {
            return auth()->user()->can('view_confidential_records');
        }

        return true;
    }

    public static function canEdit(Model $record): bool
    {
        if ($record->locked) {
            return auth()->user()->can('manage_locked_records');
        }

        return auth()->user()->can('edit_clients');
    }

    /**
     * True hard-delete, director-only — Archive (App\Filament\Support\
     * ArchiveActions, gated on archive_clients) is the normal removal path
     * now; force_delete_records is a separate, narrower permission than
     * delete_clients, which still governs child-record deletion within a
     * Client (see MattersRelationManager).
     */
    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('force_delete_records');
    }

    public static function getRelations(): array
    {
        return [
            MattersRelationManager::class,
            BackupExportsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
