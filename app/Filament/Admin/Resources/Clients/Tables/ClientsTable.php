<?php

namespace App\Filament\Admin\Resources\Clients\Tables;

use App\Enums\ClientSource;
use App\Filament\Support\ArchiveActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->searchable(),
                TextColumn::make('last_name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('source')
                    ->badge(),
                IconColumn::make('portal_enabled')
                    ->boolean(),
                IconColumn::make('locked')
                    ->boolean(),
                IconColumn::make('director_only')
                    ->boolean()
                    ->visible(fn (): bool => auth()->user()->hasRole('director')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->options(ClientSource::class),
                TernaryFilter::make('portal_enabled'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ArchiveActions::archive('archive_clients'),
                ArchiveActions::restore('archive_clients'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ArchiveActions::bulkArchive('archive_clients'),
                ]),
            ]);
    }
}
