<?php

namespace App\Filament\Admin\Resources\Leads\RelationManagers;

use App\Enums\NurtureSequenceStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NurtureSequencesRelationManager extends RelationManager
{
    protected static string $relationship = 'nurtureSequences';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->options(NurtureSequenceStatus::class)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('step')
            ->columns([
                TextColumn::make('step'),
                TextColumn::make('channel')
                    ->badge(),
                TextColumn::make('scheduled_at')
                    ->dateTime(),
                TextColumn::make('sent_at')
                    ->dateTime()
                    ->placeholder('Not sent'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->headerActions([])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()->can('edit_leads')),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('delete_leads')),
            ])
            ->toolbarActions([]);
    }
}
