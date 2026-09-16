<?php

namespace App\Filament\Admin\Resources\Matters\Tables;

use App\Enums\MatterStatus;
use App\Filament\Admin\Resources\Matters\Actions\GenerateDocumentAction;
use App\Filament\Support\ArchiveActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MattersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('client.full_name')
                    ->label('Client')
                    ->searchable(),
                TextColumn::make('practice_area')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('court_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('assignedUser.name')
                    ->label('Assigned to')
                    ->placeholder('Unassigned'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(MatterStatus::class),
                SelectFilter::make('assigned_user_id')
                    ->label('Assigned to')
                    ->relationship(
                        name: 'assignedUser',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query->role(['solicitor', 'director']),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                GenerateDocumentAction::make(),
                ArchiveActions::archive('archive_matters'),
                ArchiveActions::restore('archive_matters'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ArchiveActions::bulkArchive('archive_matters'),
                ]),
            ]);
    }
}
