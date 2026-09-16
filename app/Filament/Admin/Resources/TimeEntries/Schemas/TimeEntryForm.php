<?php

namespace App\Filament\Admin\Resources\TimeEntries\Schemas;

use App\Filament\Admin\Resources\TimeEntries\Pages\EditTimeEntry;
use App\Models\Matter;
use App\Models\TimeEntry;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class TimeEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Matter')
                    ->columns(2)
                    ->components([
                        Select::make('matter_id')
                            ->label('Matter')
                            ->options(fn (): array => Matter::query()
                                ->with('client')
                                ->get()
                                ->mapWithKeys(fn (Matter $matter): array => [
                                    $matter->id => "{$matter->reference} — {$matter->client->full_name}",
                                ])
                                ->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, ?int $state) => $set(
                                'client_display',
                                $state ? Matter::find($state)?->client?->full_name : null,
                            ))
                            ->required(),
                        TextInput::make('client_display')
                            ->label('Client')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(fn (TextInput $component, ?TimeEntry $record) => $component->state($record?->client?->full_name)),
                    ]),

                Section::make('Time')
                    ->columns(3)
                    ->components([
                        DateTimePicker::make('start_time'),
                        DateTimePicker::make('end_time'),
                        TextInput::make('duration_seconds')
                            ->label('Duration (seconds)')
                            ->numeric()
                            ->required()
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => TimeEntry::formatDuration((int) ($get('duration_seconds') ?: 0))),
                    ]),

                Section::make('Details')
                    ->columns(2)
                    ->components([
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
                    ]),

                Section::make('Billing')
                    ->columns(2)
                    ->components([
                        Toggle::make('billable')
                            ->default(true)
                            ->live(),
                        TextInput::make('billing_rate')
                            ->numeric()
                            ->prefix('£')
                            ->visible(fn (Get $get): bool => (bool) $get('billable')),
                        TextInput::make('billed_amount')
                            ->prefix('£')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Auto-calculated from duration and billing rate.'),
                        TextInput::make('invoice_display')
                            ->label('Invoice')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Not invoiced')
                            ->afterStateHydrated(fn (TextInput $component, ?TimeEntry $record) => $component->state(
                                $record?->invoice === null ? null : ($record->invoice->provider_invoice_number ?? "Draft #{$record->invoice->id}"),
                            )),
                    ]),

                EditTimeEntry::changeReasonField(),
            ]);
    }
}
