<?php

namespace App\Filament\Admin\Resources\Clients\Schemas;

use App\Enums\ClientSource;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client details')
                    ->columns(2)
                    ->components([
                        TextInput::make('first_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('last_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('address')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('source')
                            ->options(ClientSource::class)
                            ->required(),
                        DatePicker::make('date_of_birth')
                            ->native(false),
                        TextInput::make('ni_number')
                            ->label('NI number')
                            ->maxLength(255)
                            ->helperText('Stored encrypted.'),
                    ]),

                Section::make('Client portal')
                    ->columns(2)
                    ->components([
                        Toggle::make('portal_enabled'),
                        DateTimePicker::make('portal_last_login')
                            ->disabled()
                            ->dehydrated(false),
                    ]),

                Section::make('Internal')
                    ->columns(2)
                    ->components([
                        Textarea::make('notes')
                            ->rows(4)
                            ->columnSpanFull(),
                        Toggle::make('locked')
                            ->helperText('Only directors can lock or unlock records.')
                            ->visible(fn (): bool => auth()->user()->can('manage_locked_records')),
                        Toggle::make('director_only')
                            ->helperText('Only directors can see director-only records.')
                            ->visible(fn (): bool => auth()->user()->can('view_confidential_records')),
                    ]),
            ]);
    }
}
