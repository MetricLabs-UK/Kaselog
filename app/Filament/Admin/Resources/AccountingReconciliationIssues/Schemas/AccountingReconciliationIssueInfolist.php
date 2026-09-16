<?php

namespace App\Filament\Admin\Resources\AccountingReconciliationIssues\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountingReconciliationIssueInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Issue')
                    ->columns(3)
                    ->components([
                        TextEntry::make('created_at')
                            ->label('When')
                            ->dateTime(),
                        TextEntry::make('provider')
                            ->badge(),
                        TextEntry::make('reason')
                            ->badge(),
                        TextEntry::make('external_invoice_id')
                            ->label('Provider invoice ID')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('instalment.amount')
                            ->label('Instalment')
                            ->money('GBP')
                            ->placeholder('—'),
                    ]),

                Section::make('Review')
                    ->columns(3)
                    ->components([
                        IconEntry::make('needs_review')
                            ->boolean(),
                        TextEntry::make('reviewedBy.name')
                            ->label('Reviewed by')
                            ->placeholder('Not yet reviewed'),
                        TextEntry::make('reviewed_at')
                            ->label('Reviewed at')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),

                Section::make('Details')
                    ->components([
                        TextEntry::make('payload')
                            ->hiddenLabel()
                            ->state(fn (?array $state): string => $state ? json_encode($state, JSON_PRETTY_PRINT) : 'No further detail recorded.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
