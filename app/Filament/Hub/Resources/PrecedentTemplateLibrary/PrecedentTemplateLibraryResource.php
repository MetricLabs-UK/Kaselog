<?php

namespace App\Filament\Hub\Resources\PrecedentTemplateLibrary;

use App\Filament\Hub\Resources\PrecedentTemplateLibrary\Pages\CreatePrecedentTemplateLibraryItem;
use App\Filament\Hub\Resources\PrecedentTemplateLibrary\Pages\EditPrecedentTemplateLibraryItem;
use App\Filament\Hub\Resources\PrecedentTemplateLibrary\Pages\ListPrecedentTemplateLibrary;
use App\Filament\Hub\Resources\PrecedentTemplateLibrary\Schemas\PrecedentTemplateLibraryForm;
use App\Filament\Hub\Resources\PrecedentTemplateLibrary\Tables\PrecedentTemplateLibraryTable;
use App\Models\PrecedentTemplate;
use App\Support\Hub\HubAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Manages master (is_master = true) PrecedentTemplate rows only — the
 * library firms adopt from into their own tenant-scoped copies. These rows
 * have no tenant_id of their own, so the model's ordinary TenantScope global
 * scope (which the Hub, having no ambient tenant, would otherwise fail
 * closed against) is bypassed here via allTenants(), same escape hatch
 * PhoneLookup uses.
 */
class PrecedentTemplateLibraryResource extends Resource
{
    protected static ?string $model = PrecedentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $navigationLabel = 'Template Library';

    protected static ?string $modelLabel = 'master template';

    protected static ?string $pluralModelLabel = 'master templates';

    public static function getEloquentQuery(): Builder
    {
        return PrecedentTemplate::allTenants()->where('is_master', true);
    }

    public static function form(Schema $schema): Schema
    {
        return PrecedentTemplateLibraryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrecedentTemplateLibraryTable::configure($table);
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can(HubAccess::PERMISSION_MANAGE_TEMPLATE_LIBRARY);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can(HubAccess::PERMISSION_MANAGE_TEMPLATE_LIBRARY);
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can(HubAccess::PERMISSION_MANAGE_TEMPLATE_LIBRARY);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can(HubAccess::PERMISSION_MANAGE_TEMPLATE_LIBRARY);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrecedentTemplateLibrary::route('/'),
            'create' => CreatePrecedentTemplateLibraryItem::route('/create'),
            'edit' => EditPrecedentTemplateLibraryItem::route('/{record}/edit'),
        ];
    }
}
