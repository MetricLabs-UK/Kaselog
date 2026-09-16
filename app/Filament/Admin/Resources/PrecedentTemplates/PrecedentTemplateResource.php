<?php

namespace App\Filament\Admin\Resources\PrecedentTemplates;

use App\Filament\Admin\Resources\PrecedentTemplates\Pages\CreatePrecedentTemplate;
use App\Filament\Admin\Resources\PrecedentTemplates\Pages\EditPrecedentTemplate;
use App\Filament\Admin\Resources\PrecedentTemplates\Pages\ListPrecedentTemplates;
use App\Filament\Admin\Resources\PrecedentTemplates\Pages\ViewPrecedentTemplate;
use App\Filament\Admin\Resources\PrecedentTemplates\Schemas\PrecedentTemplateForm;
use App\Filament\Admin\Resources\PrecedentTemplates\Schemas\PrecedentTemplateInfolist;
use App\Filament\Admin\Resources\PrecedentTemplates\Tables\PrecedentTemplatesTable;
use App\Models\PrecedentTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PrecedentTemplateResource extends Resource
{
    protected static ?string $model = PrecedentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    /**
     * Reached via the sidebar user menu (AdminPanelProvider) instead, to
     * keep the main nav focused on day-to-day work.
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return PrecedentTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PrecedentTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrecedentTemplatesTable::configure($table);
    }

    /**
     * Same role tier as Matters (director/admin/solicitor). Accounts is
     * deliberately excluded: templates feed client-facing generated
     * documents, and the accounts role has no reason to author them —
     * audit finding F6 (this resource previously had no gate at all).
     */
    public static function canAccess(): bool
    {
        return auth()->user()->can('view_precedent_templates');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('create_precedent_templates');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('edit_precedent_templates');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('delete_precedent_templates');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrecedentTemplates::route('/'),
            'create' => CreatePrecedentTemplate::route('/create'),
            'view' => ViewPrecedentTemplate::route('/{record}'),
            'edit' => EditPrecedentTemplate::route('/{record}/edit'),
        ];
    }
}
