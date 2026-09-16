<?php

namespace App\Filament\Admin\Resources\PaymentPlans\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChaseLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'chaseLogs';

    protected static ?string $title = 'Chase Log';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('instalment.amount')
                    ->label('Instalment amount')
                    ->money('GBP'),
                TextColumn::make('instalment.due_date')
                    ->label('Instalment due')
                    ->date(),
                TextColumn::make('channel')
                    ->badge(),
                TextColumn::make('template'),
                TextColumn::make('sent_at')
                    ->dateTime(),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
