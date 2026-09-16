<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Filament\Support\ChangeReasonField;
use App\Models\TimeEntry;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TimeEntryRelationManager extends RelationManager
{
    protected static string $relationship = 'timeEntries';

    protected static ?string $title = 'Time Entries';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimePicker::make('start_time'),
                DateTimePicker::make('end_time'),
                TextInput::make('duration_seconds')
                    ->label('Duration (seconds)')
                    ->numeric()
                    ->required()
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => TimeEntry::formatDuration((int) ($get('duration_seconds') ?: 0))),
                Select::make('activity_type')
                    ->options([
                        'Attendance' => 'Attendance',
                        'Drafting' => 'Drafting',
                        'Research' => 'Research',
                        'Telephone' => 'Telephone',
                        'Correspondence' => 'Correspondence',
                        'Other' => 'Other',
                    ]),
                Textarea::make('description')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull(),
                Toggle::make('billable')
                    ->default(true)
                    ->live(),
                TextInput::make('billing_rate')
                    ->numeric()
                    ->prefix('£')
                    ->visible(fn (Get $get): bool => (bool) $get('billable')),
                TextInput::make('invoice_display')
                    ->label('Invoice')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('Not invoiced')
                    ->afterStateHydrated(fn (TextInput $component, ?TimeEntry $record) => $component->state(
                        $record?->invoice === null ? null : ($record->invoice->provider_invoice_number ?? "Draft #{$record->invoice->id}"),
                    )),
                ChangeReasonField::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): void {
                $user = auth()->user();

                if ($user->hasRole('solicitor') && config('kaselog.scope_solicitors')) {
                    $query->where('user_id', $user->id);
                }
            })
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('user.name')
                    ->label('User'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->date(),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => TimeEntry::formatDuration($state))
                    ->summarize(
                        Summarizer::make()
                            ->label('Total hours')
                            ->using(fn ($query): string => TimeEntry::formatDuration((int) $query->sum('duration_seconds'))),
                    ),
                TextColumn::make('activity_type')
                    ->placeholder('—'),
                IconColumn::make('billable')
                    ->boolean(),
                TextColumn::make('billed_amount')
                    ->money('GBP')
                    ->placeholder('—')
                    ->summarize(
                        Sum::make()
                            ->label('Total billed')
                            ->money('GBP'),
                    ),
                TextColumn::make('invoice')
                    ->label('Invoice')
                    // ->state(), not dot-notation + formatStateUsing — see
                    // TimeEntriesTable's identical comment for why.
                    ->state(fn (TimeEntry $record): ?string => $record->invoice === null
                        ? null
                        : ($record->invoice->provider_invoice_number ?? "Draft #{$record->invoice->id}"))
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()->can('create_time_entries')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (TimeEntry $record): bool => match (true) {
                        $record->locked || $record->invoice_id !== null => auth()->user()->can('manage_locked_records'),
                        auth()->user()->can('edit_any_time_entry') => true,
                        default => auth()->user()->can('edit_own_time_entry') && $record->user_id === auth()->id(),
                    })
                    ->using(function (array $data, TimeEntry $record): TimeEntry {
                        $reason = $data['change_reason'] ?? null;
                        unset($data['change_reason']);

                        $record->updateWithReason($data, $reason);

                        return $record;
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('delete_time_entries')),
            ]);
    }
}
