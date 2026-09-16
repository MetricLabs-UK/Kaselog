<?php

namespace App\Filament\Admin\Resources\PaymentPlans\Schemas;

use App\Models\Matter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment plan')
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
                            ->required(),
                        TextInput::make('total_amount')
                            ->numeric()
                            ->prefix('£')
                            ->required(),
                        TextInput::make('deposit_amount')
                            ->numeric()
                            ->prefix('£')
                            ->required(),
                        DatePicker::make('deposit_paid_at'),
                        Textarea::make('notes')
                            ->rows(4)
                            ->columnSpanFull(),
                        Toggle::make('locked')
                            ->helperText('Only directors can lock or unlock records.')
                            ->visible(fn (): bool => auth()->user()->can('manage_locked_records')),
                    ]),
            ]);
    }
}
