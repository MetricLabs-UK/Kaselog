<?php

namespace App\Filament\Admin\Resources\PaymentPlans\Tables;

use App\Filament\Support\ArchiveActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('matter.reference')
                    ->label('Matter')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('matter.client.full_name')
                    ->label('Client')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->money('GBP')
                    ->sortable(),
                TextColumn::make('deposit_amount')
                    ->money('GBP'),
                TextColumn::make('deposit_paid_at')
                    ->date()
                    ->placeholder('Not paid'),
                TextColumn::make('instalments_count')
                    ->label('Instalments')
                    ->counts('instalments'),
                TextColumn::make('amount_outstanding')
                    ->label('Outstanding')
                    ->money('GBP'),
                IconColumn::make('locked')
                    ->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ArchiveActions::archive('archive_payment_plans'),
                ArchiveActions::restore('archive_payment_plans'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ArchiveActions::bulkArchive('archive_payment_plans'),
                ]),
            ]);
    }
}
