<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Filament\Admin\Resources\CallNotes\Tables\CallNotesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Section 8 phase 1 — "viewable per-matter". Same columns/actions as the
 * cross-matter queue (App\Filament\Admin\Resources\CallNotes\
 * CallNoteResource), just pre-filtered to this Matter's own calls.
 */
class CallNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'callNotes';

    protected static ?string $title = 'Call Notes';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return CallNotesTable::configure($table)
            ->recordTitleAttribute('summary');
    }
}
