<?php

namespace App\Filament\Admin\Resources\CallNotes;

use App\Filament\Admin\Resources\CallNotes\Pages\ListCallNotes;
use App\Filament\Admin\Resources\CallNotes\Pages\ViewCallNote;
use App\Filament\Admin\Resources\CallNotes\Schemas\CallNoteInfolist;
use App\Filament\Admin\Resources\CallNotes\Tables\CallNotesTable;
use App\Models\CallNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The needs_review queue (Section 8 phase 1) — read + review only, no
 * create/edit/delete. Call notes only ever come from RetellWebhookController;
 * staff review and mark them, they don't author them.
 */
class CallNoteResource extends Resource
{
    protected static ?string $model = CallNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $navigationLabel = 'Call Notes';

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    public static function infolist(Schema $schema): Schema
    {
        return CallNoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CallNotesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Same confidentiality rule MatterResource/ClientResource/
        // LeadResource already enforce at the query level (audit finding
        // F20) — a call note discussing a director_only matter or client is
        // just as confidential as the record itself. A linked Matter's own
        // director_only flag doesn't automatically mirror its Client's (see
        // MatterResource::getEloquentQuery(), which checks both
        // independently) — so this checks matter.director_only,
        // matter.client.director_only, *and* the note's own direct
        // client.director_only (for notes linked via client_id with no
        // matter at all) separately, not just the first of the three.
        if (! auth()->user()->can('view_confidential_records')) {
            $query->where(function (Builder $query): void {
                $query->whereDoesntHave('matter', fn (Builder $q) => $q->where('director_only', true))
                    ->whereDoesntHave('matter.client', fn (Builder $q) => $q->where('director_only', true))
                    ->whereDoesntHave('client', fn (Builder $q) => $q->where('director_only', true));
            });
        }

        return $query;
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('view_call_notes');
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
            'index' => ListCallNotes::route('/'),
            'view' => ViewCallNote::route('/{record}'),
        ];
    }
}
