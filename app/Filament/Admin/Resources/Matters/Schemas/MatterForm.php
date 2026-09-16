<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use App\Enums\MatterOutcome;
use App\Enums\MatterPlea;
use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Tab groupings mirror MatterViewTabs so editing feels like a natural
 * extension of viewing, not a different page — Time/Documents/
 * Communications/Ask Quill have no editable fields of their own so they
 * have no tab here, same fields as before, just regrouped.
 */
class MatterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Filament's default root schema width for a Resource *form* is
            // 2 columns (infolists default to 1 — see MatterViewTabs, which
            // sets this explicitly for the same reason). Tabs::make() below
            // is the only top-level component, so without this it only
            // filled column 1, leaving the right half of the page empty —
            // this was the page-width bug.
            ->columns(1)
            ->components([
                View::make('filament.matters.tab-styles'),

                Tabs::make()
                    ->extraAttributes(['class' => 'cs-matter-tabs'])
                    ->tabs([
                        static::summaryTab(),
                        static::caseDetailsTab(),
                        static::financeTab(),
                        static::notesTab(),
                    ]),
            ]);
    }

    protected static function summaryTab(): Tab
    {
        return Tab::make('Summary')
            ->icon(Heroicon::OutlinedIdentification)
            ->schema([
                Grid::make(2)
                    ->components([
                        Section::make('Matter')
                            ->columns(2)
                            ->components([
                                Select::make('client_id')
                                    ->label('Client')
                                    ->options(fn (): array => Client::query()
                                        ->get()
                                        ->mapWithKeys(fn (Client $client): array => [$client->id => $client->full_name])
                                        ->all())
                                    ->searchable()
                                    ->required(),
                                TextInput::make('reference')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->placeholder('Auto-generated on save'),
                                Select::make('status')
                                    ->options(MatterStatus::class)
                                    ->default(MatterStatus::Active)
                                    ->required(),
                            ]),

                        Group::make([
                            Section::make('Assignment')
                                ->columns(2)
                                ->components([
                                    Select::make('assigned_user_id')
                                        ->label('Assigned to')
                                        ->relationship(
                                            name: 'assignedUser',
                                            titleAttribute: 'name',
                                            modifyQueryUsing: fn ($query) => $query->role(['solicitor', 'director']),
                                        )
                                        ->searchable()
                                        ->nullable(),
                                    Select::make('supervising_user_id')
                                        ->label('Supervising user')
                                        ->relationship(name: 'supervisingUser', titleAttribute: 'name')
                                        ->searchable()
                                        ->nullable(),
                                ]),

                            Section::make('Compliance Checklist')
                                ->columns(3)
                                ->components([
                                    Toggle::make('client_care_sent'),
                                    Toggle::make('aml_verified'),
                                    Toggle::make('conflict_checked'),
                                    Toggle::make('gdpr_sent'),
                                    Toggle::make('file_review_done'),
                                    DatePicker::make('costs_updated'),
                                ]),
                        ]),
                    ]),
            ]);
    }

    protected static function caseDetailsTab(): Tab
    {
        return Tab::make('Case Details')
            ->icon(Heroicon::OutlinedScale)
            ->schema([
                Grid::make(2)
                    ->components([
                        Section::make('Case Details')
                            ->columns(2)
                            ->components([
                                TextInput::make('title')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                TextInput::make('urn')
                                    ->label('URN')
                                    ->helperText('Police/court unique reference number.')
                                    ->maxLength(255),
                                TextInput::make('practice_area')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('hearing_type')
                                    ->placeholder('First hearing, trial, mention...')
                                    ->maxLength(255),
                                DatePicker::make('offence_date'),
                                TextInput::make('offence_location')
                                    ->maxLength(255),
                                Select::make('plea')
                                    ->options(MatterPlea::class),
                                Select::make('outcome')
                                    ->options(MatterOutcome::class),
                                Textarea::make('sentence')
                                    ->helperText('Fines, points, ban length etc.')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),

                        Group::make([
                            Section::make('Source & Lead')
                                ->columns(2)
                                ->components([
                                    TextInput::make('source')
                                        ->placeholder('Google Ads, referral, walk-in...')
                                        ->maxLength(255),
                                    Select::make('lead_id')
                                        ->label('Lead')
                                        ->options(fn (): array => Lead::withoutGlobalScope(ExcludeConvertedLeadsScope::class)
                                            ->get()
                                            ->mapWithKeys(fn (Lead $lead): array => [$lead->id => $lead->full_name])
                                            ->all())
                                        ->searchable()
                                        ->nullable(),
                                ]),

                            Section::make('Court')
                                ->columns(2)
                                ->components([
                                    TextInput::make('court_name')
                                        ->maxLength(255),
                                    DatePicker::make('court_date'),
                                    DatePicker::make('instruction_date'),
                                    DatePicker::make('limitation_date')
                                        ->helperText('Regulatory deadline.'),
                                    DatePicker::make('closed_date'),
                                ]),
                        ]),
                    ]),
            ]);
    }

    protected static function financeTab(): Tab
    {
        return Tab::make('Finance')
            ->icon(Heroicon::OutlinedBanknotes)
            // Same permission as MatterViewTabs::financeTab() and
            // PaymentPlanResource::canAccess() — one enforcement point.
            ->visible(fn (): bool => auth()->user()->can('view_finance'))
            ->schema([
                Section::make('Financials')
                    ->columns(2)
                    ->components([
                        TextInput::make('agreed_fee')
                            ->numeric()
                            ->prefix('£'),
                    ]),
            ]);
    }

    protected static function notesTab(): Tab
    {
        return Tab::make('Notes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema([
                Section::make('Internal Notes')
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
