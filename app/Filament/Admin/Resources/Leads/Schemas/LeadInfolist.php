<?php

namespace App\Filament\Admin\Resources\Leads\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact')
                    ->columns(2)
                    ->components([
                        TextEntry::make('full_name'),
                        TextEntry::make('email'),
                        TextEntry::make('telephone')
                            ->placeholder('Not set'),
                        TextEntry::make('mobile')
                            ->placeholder('Not set'),
                    ]),

                Section::make('Enquiry')
                    ->columns(2)
                    ->components([
                        TextEntry::make('source')
                            ->badge(),
                        TextEntry::make('campaign_source')
                            ->placeholder('Not set'),
                        TextEntry::make('gclid')
                            ->label('GCLID')
                            ->placeholder('Not set'),
                        TextEntry::make('practice_area'),
                        TextEntry::make('message')
                            ->columnSpanFull(),
                    ]),

                Section::make('Assignment')
                    ->columns(2)
                    ->components([
                        TextEntry::make('assignedUser.name')
                            ->label('Assigned to')
                            ->placeholder('Unassigned'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('nurture_stage'),
                        TextEntry::make('chase_date')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('last_contacted_at')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('converted_at')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('convertedClient.full_name')
                            ->label('Converted to client')
                            ->placeholder('Not converted'),
                    ]),

                Section::make('Internal')
                    ->columns(2)
                    ->components([
                        IconEntry::make('locked')
                            ->boolean(),
                        IconEntry::make('director_only')
                            ->boolean()
                            ->visible(fn (): bool => auth()->user()->hasRole('director')),
                    ]),
            ]);
    }
}
