<?php

namespace App\Filament\Admin\Resources\PaymentPlans\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentPlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment plan')
                    ->columns(2)
                    ->components([
                        TextEntry::make('matter.reference')
                            ->label('Matter'),
                        TextEntry::make('matter.client.full_name')
                            ->label('Client'),
                        TextEntry::make('total_amount')
                            ->money('GBP'),
                        TextEntry::make('deposit_amount')
                            ->money('GBP'),
                        TextEntry::make('deposit_paid_at')
                            ->date()
                            ->placeholder('Not paid'),
                        TextEntry::make('amount_outstanding')
                            ->label('Amount outstanding')
                            ->money('GBP'),
                        TextEntry::make('notes')
                            ->placeholder('Not set')
                            ->columnSpanFull(),
                        IconEntry::make('locked')
                            ->boolean(),
                    ]),
            ]);
    }
}
