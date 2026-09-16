<?php

namespace App\Filament\Admin\Resources\TimeEntries\Schemas;

use App\Models\TimeEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TimeEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Matter')
                    ->columns(2)
                    ->components([
                        TextEntry::make('matter.reference')
                            ->label('Matter'),
                        TextEntry::make('client.full_name')
                            ->label('Client'),
                        TextEntry::make('user.name')
                            ->label('Logged by'),
                    ]),

                Section::make('Time')
                    ->columns(3)
                    ->components([
                        TextEntry::make('start_time')
                            ->dateTime()
                            ->placeholder('Not set'),
                        TextEntry::make('end_time')
                            ->dateTime()
                            ->placeholder('Not set'),
                        TextEntry::make('duration_seconds')
                            ->label('Duration')
                            ->formatStateUsing(fn (int $state): string => TimeEntry::formatDuration($state)),
                    ]),

                Section::make('Details')
                    ->columns(2)
                    ->components([
                        TextEntry::make('activity_type')
                            ->placeholder('Not set'),
                        TextEntry::make('description')
                            ->columnSpanFull(),
                    ]),

                Section::make('Billing')
                    ->columns(2)
                    ->components([
                        IconEntry::make('billable')
                            ->boolean(),
                        TextEntry::make('billing_rate')
                            ->money('GBP')
                            ->placeholder('Not set'),
                        TextEntry::make('billed_amount')
                            ->money('GBP')
                            ->placeholder('Not set'),
                        TextEntry::make('invoice')
                            ->label('Invoice')
                            // ->state(), not dot-notation + formatStateUsing
                            // — see TimeEntriesTable's identical comment for
                            // why: the draft case (no provider_invoice_number
                            // yet) would otherwise render the placeholder
                            // even though a real Invoice is attached.
                            ->state(fn (TimeEntry $record): ?string => $record->invoice === null
                                ? null
                                : ($record->invoice->provider_invoice_number ?? "Draft #{$record->invoice->id}"))
                            ->placeholder('Not invoiced'),
                    ]),
            ]);
    }
}
