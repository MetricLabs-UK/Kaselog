<?php

namespace App\Filament\Admin\Resources\Invoices\Schemas;

use App\Models\Invoice;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice')
                    ->columns(3)
                    ->components([
                        TextEntry::make('matter.reference')
                            ->label('Matter'),
                        TextEntry::make('client.full_name')
                            ->label('Client'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('total_amount')
                            ->money('GBP'),
                        TextEntry::make('provider_invoice_number')
                            ->label('Provider invoice')
                            ->placeholder('Not sent'),
                        TextEntry::make('sent_at')
                            ->dateTime()
                            ->placeholder('Not sent'),
                        TextEntry::make('paid_at')
                            ->dateTime()
                            ->placeholder('Not paid'),
                        IconEntry::make('out_of_sync_with_provider')
                            ->label('Possibly out of sync')
                            ->boolean(),
                    ]),

                Section::make('Instalment')
                    ->visible(fn (Invoice $record): bool => $record->instalments->isNotEmpty())
                    ->components([
                        RepeatableEntry::make('instalments')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('due_date')->date(),
                                TextEntry::make('amount')->money('GBP'),
                                TextEntry::make('display_status')->label('Status')->badge(),
                            ])
                            ->columns(3),
                    ]),

                Section::make('Time entries')
                    ->visible(fn (Invoice $record): bool => $record->timeEntries->isNotEmpty())
                    ->components([
                        RepeatableEntry::make('timeEntries')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('created_at')->label('Date')->date(),
                                TextEntry::make('activity_type')->placeholder('—'),
                                TextEntry::make('description')->columnSpan(2),
                                TextEntry::make('billed_amount')->money('GBP'),
                            ])
                            ->columns(5),
                    ]),
            ]);
    }
}
