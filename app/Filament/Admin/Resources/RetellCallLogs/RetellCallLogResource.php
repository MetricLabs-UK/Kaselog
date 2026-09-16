<?php

namespace App\Filament\Admin\Resources\RetellCallLogs;

use App\Filament\Admin\Resources\RetellCallLogs\Pages\ListRetellCallLogs;
use App\Filament\Admin\Resources\RetellCallLogs\Pages\ViewRetellCallLog;
use App\Filament\Admin\Resources\RetellCallLogs\Schemas\RetellCallLogInfolist;
use App\Filament\Admin\Resources\RetellCallLogs\Tables\RetellCallLogsTable;
use App\Models\RetellCallLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Section 9 — the general "browse everything the phone system captured"
 * view, distinct from CallNoteResource's needs_review triage queue: this
 * shows every call (matched, unmatched, or not yet analysed at all), read
 * only, direct from the raw Retell webhook capture.
 *
 * A real call delivers 2-3 separate webhook events (call_started,
 * call_ended, call_analyzed), each its own RetellCallLog row sharing one
 * call_id — the query below collapses that down to one row per call_id (the
 * latest/most complete event), so "browse all captured calls" means one row
 * per real call, not per webhook delivery. See RetellCallLog's own docblock.
 *
 * Same access restriction as CallNoteResource (view_call_notes — director/
 * admin per TenantRoleSeeder) rather than a new permission: raw transcripts
 * and recordings for *every* call (including ones CallNoteResource's queue
 * never surfaces because they were never flagged) are at least as sensitive
 * as the triaged queue, not less — no case for loosening this.
 */
class RetellCallLogResource extends Resource
{
    protected static ?string $model = RetellCallLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Call Logs';

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    public static function infolist(Schema $schema): Schema
    {
        return RetellCallLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RetellCallLogsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('callNote');

        // One row per call_id — whichever event for that call has the
        // highest id (events arrive in order: started, ended, analyzed, so
        // the highest id is always the most complete one available so far).
        $query->whereIn('id', function ($subQuery) {
            $subQuery->select(DB::raw('MAX(id)'))
                ->from('retell_call_logs')
                ->groupBy('call_id');
        });

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
            'index' => ListRetellCallLogs::route('/'),
            'view' => ViewRetellCallLog::route('/{record}'),
        ];
    }
}
