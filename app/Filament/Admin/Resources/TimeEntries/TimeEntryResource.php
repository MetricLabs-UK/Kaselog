<?php

namespace App\Filament\Admin\Resources\TimeEntries;

use App\Filament\Admin\Resources\TimeEntries\Pages\CreateTimeEntry;
use App\Filament\Admin\Resources\TimeEntries\Pages\EditTimeEntry;
use App\Filament\Admin\Resources\TimeEntries\Pages\ListTimeEntries;
use App\Filament\Admin\Resources\TimeEntries\Pages\ViewTimeEntry;
use App\Filament\Admin\Resources\TimeEntries\Schemas\TimeEntryForm;
use App\Filament\Admin\Resources\TimeEntries\Schemas\TimeEntryInfolist;
use App\Filament\Admin\Resources\TimeEntries\Tables\TimeEntriesTable;
use App\Models\TimeEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TimeEntryResource extends Resource
{
    protected static ?string $model = TimeEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    /**
     * Not needed in the main nav day-to-day, but still a genuinely useful
     * view (every time entry across every matter, searchable/sortable/
     * filterable by client, user, billable status, invoice) rather than one
     * matter's entries — so it's kept reachable via the sidebar user menu
     * (AdminPanelProvider), same treatment as PrecedentTemplateResource,
     * instead of being deleted outright.
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return TimeEntryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TimeEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TimeEntriesTable::configure($table);
    }

    /**
     * Deliberately every role — accounts needs read access to time entries
     * for billing; create/edit/delete are individually gated below.
     */
    public static function canAccess(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        // Same SCOPE_SOLICITORS treatment as MatterResource — extended here to
        // close a previously-flagged inconsistency (the flag scoped Matters
        // but not TimeEntries). A feature flag, not a confidentiality
        // boundary — stays a literal role check.
        if ($user->hasRole('solicitor') && config('kaselog.scope_solicitors')) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('create_time_entries');
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        // Bundled into an invoice (Section 6) is edit-protected the same way
        // a manually locked record is, regardless of the locked flag's own
        // current value — otherwise flipping locked back off would silently
        // reopen an already-invoiced entry to non-privileged edits.
        if ($record->locked || $record->invoice_id !== null) {
            return $user->can('manage_locked_records');
        }

        if ($user->can('edit_any_time_entry')) {
            return true;
        }

        return $user->can('edit_own_time_entry') && $record->user_id === auth()->id();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('delete_time_entries');
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
            'index' => ListTimeEntries::route('/'),
            'create' => CreateTimeEntry::route('/create'),
            'view' => ViewTimeEntry::route('/{record}'),
            'edit' => EditTimeEntry::route('/{record}/edit'),
        ];
    }
}
