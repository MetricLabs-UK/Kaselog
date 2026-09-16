<?php

namespace App\Filament\Admin\Resources\TimeEntries\Tables;

use App\Models\TimeEntry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TimeEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('matter.reference')
                    ->label('Matter')
                    ->searchable(),
                TextColumn::make('client.full_name')
                    ->label('Client')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => TimeEntry::formatDuration($state)),
                TextColumn::make('activity_type')
                    ->placeholder('—'),
                IconColumn::make('billable')
                    ->boolean(),
                TextColumn::make('billed_amount')
                    ->money('GBP')
                    ->placeholder('—'),
                TextColumn::make('invoice')
                    ->label('Invoice')
                    // ->state() (not dot-notation + formatStateUsing): the
                    // draft case has no provider_invoice_number yet, and
                    // TextColumn's blank-state placeholder check runs on the
                    // *raw* resolved value before formatStateUsing ever
                    // sees it — a null provider_invoice_number would show
                    // the placeholder even with a real, still-draft Invoice
                    // attached. Found via a real render, not by inspection.
                    ->state(fn (TimeEntry $record): ?string => $record->invoice === null
                        ? null
                        : ($record->invoice->provider_invoice_number ?? "Draft #{$record->invoice->id}"))
                    ->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()->can('delete_time_entries')),
                ]),
            ]);
    }
}
