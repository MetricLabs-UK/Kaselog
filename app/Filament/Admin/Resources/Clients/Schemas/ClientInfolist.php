<?php

namespace App\Filament\Admin\Resources\Clients\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client details')
                    ->columns(2)
                    ->components([
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('email'),
                        TextEntry::make('phone'),
                        TextEntry::make('address')
                            ->columnSpanFull(),
                        TextEntry::make('source')
                            ->badge(),
                        TextEntry::make('date_of_birth')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('ni_number')
                            ->label('NI number')
                            ->placeholder('Not set'),
                    ]),

                Section::make('Client portal')
                    ->columns(2)
                    ->components([
                        IconEntry::make('portal_enabled')
                            ->boolean(),
                        TextEntry::make('portal_last_login')
                            ->dateTime()
                            ->placeholder('Never'),
                    ]),

                Section::make('Internal')
                    ->columns(2)
                    ->components([
                        TextEntry::make('notes')
                            ->columnSpanFull(),
                        IconEntry::make('locked')
                            ->boolean(),
                        IconEntry::make('director_only')
                            ->boolean()
                            ->visible(fn (): bool => auth()->user()->hasRole('director')),
                    ]),
            ]);
    }
}
