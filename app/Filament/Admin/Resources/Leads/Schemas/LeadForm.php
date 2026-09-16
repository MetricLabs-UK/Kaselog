<?php

namespace App\Filament\Admin\Resources\Leads\Schemas;

use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact')
                    ->columns(2)
                    ->components([
                        Select::make('prefix')
                            ->options([
                                'Mr' => 'Mr',
                                'Mrs' => 'Mrs',
                                'Dr' => 'Dr',
                                'Miss' => 'Miss',
                                'Ms' => 'Ms',
                            ])
                            ->nullable(),
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
                        TextInput::make('telephone')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('mobile')
                            ->tel()
                            ->maxLength(255),
                    ]),

                Section::make('Enquiry')
                    ->columns(2)
                    ->components([
                        Select::make('source')
                            ->options(ClientSource::class)
                            ->required(),
                        TextInput::make('campaign_source')
                            ->maxLength(255),
                        TextInput::make('gclid')
                            ->label('GCLID')
                            ->maxLength(255),
                        TextInput::make('practice_area')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('message')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Assignment')
                    ->columns(2)
                    ->components([
                        Select::make('assigned_user_id')
                            ->label('Assigned to')
                            ->relationship(
                                name: 'assignedUser',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) => $query->role(['director', 'solicitor', 'admin']),
                            )
                            ->searchable()
                            ->nullable(),
                        Select::make('status')
                            ->options(LeadStatus::class)
                            ->required(),
                        TextInput::make('nurture_stage')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false),
                        DatePicker::make('chase_date'),
                        DatePicker::make('last_contacted_at'),
                        DatePicker::make('converted_at'),
                        TextInput::make('convertedClient.full_name')
                            ->label('Converted to client')
                            ->disabled()
                            ->dehydrated(false),
                    ]),

                Section::make('Internal')
                    ->columns(2)
                    ->components([
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
