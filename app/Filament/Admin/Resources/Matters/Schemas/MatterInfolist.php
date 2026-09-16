<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MatterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Matter')
                    ->columns(2)
                    ->components([
                        TextEntry::make('reference'),
                        TextEntry::make('client.full_name')
                            ->label('Client'),
                        TextEntry::make('status')
                            ->badge(),
                    ]),

                Section::make('Case Details')
                    ->columns(2)
                    ->components([
                        TextEntry::make('title')
                            ->placeholder('Not set')
                            ->columnSpanFull(),
                        TextEntry::make('urn')
                            ->label('URN')
                            ->placeholder('Not set'),
                        TextEntry::make('practice_area'),
                        TextEntry::make('hearing_type')
                            ->placeholder('Not set'),
                        TextEntry::make('offence_date')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('offence_location')
                            ->placeholder('Not set'),
                        TextEntry::make('plea')
                            ->badge()
                            ->placeholder('Not set'),
                        TextEntry::make('outcome')
                            ->badge()
                            ->placeholder('Not set'),
                        TextEntry::make('sentence')
                            ->placeholder('Not set')
                            ->columnSpanFull(),
                    ]),

                Section::make('Court')
                    ->columns(2)
                    ->components([
                        TextEntry::make('court_date')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('instruction_date')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('limitation_date')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('closed_date')
                            ->date()
                            ->placeholder('Not set'),
                    ]),

                Section::make('Assignment')
                    ->columns(2)
                    ->components([
                        TextEntry::make('assignedUser.name')
                            ->label('Assigned to')
                            ->placeholder('Unassigned'),
                        TextEntry::make('supervisingUser.name')
                            ->label('Supervising user')
                            ->placeholder('None'),
                        TextEntry::make('source')
                            ->placeholder('Not set'),
                        TextEntry::make('lead.full_name')
                            ->label('Lead')
                            ->placeholder('None'),
                    ]),

                Section::make('Financials')
                    ->columns(2)
                    ->visible(fn (): bool => auth()->user()->can('view_finance'))
                    ->components([
                        TextEntry::make('agreed_fee')
                            ->money('GBP')
                            ->placeholder('Not set'),
                    ]),

                Section::make('Compliance Checklist')
                    ->columns(3)
                    ->components([
                        IconEntry::make('client_care_sent')
                            ->boolean(),
                        IconEntry::make('aml_verified')
                            ->boolean(),
                        IconEntry::make('conflict_checked')
                            ->boolean(),
                        IconEntry::make('gdpr_sent')
                            ->boolean(),
                        IconEntry::make('file_review_done')
                            ->boolean(),
                        TextEntry::make('costs_updated')
                            ->date()
                            ->placeholder('Not set'),
                    ]),

                Section::make('Internal Notes')
                    ->columns(2)
                    ->components([
                        TextEntry::make('notes')
                            ->placeholder('Not set')
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
