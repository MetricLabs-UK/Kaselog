<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use App\Enums\InstalmentStatus;
use App\Enums\PortalStatus;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Leads\LeadResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\CallNotesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterDocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterMessagesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TimeEntryRelationManager;
use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Livewire\QuillChat;
use App\Models\GeneratedDocument;
use App\Models\Instalment;
use App\Models\Matter;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class MatterViewTabs
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.matters.tab-styles'),

                Tabs::make()
                    ->extraAttributes(['class' => 'cs-matter-tabs'])
                    ->persistTabInQueryString()
                    ->tabs([
                        static::summaryTab(),
                        static::caseDetailsTab(),
                        static::financeTab(),
                        static::timeTab(),
                        static::documentsTab(),
                        static::communicationsTab(),
                        static::quillTab(),
                        static::notesTab(),
                    ]),
            ]);
    }

    protected static function summaryTab(): Tab
    {
        return Tab::make('Summary')
            ->icon(Heroicon::OutlinedIdentification)
            ->schema([
                Section::make('Overview')
                    ->columns(3)
                    ->components([
                        TextEntry::make('reference'),
                        TextEntry::make('client.full_name')
                            ->label('Client')
                            ->url(fn (Matter $record): ?string => $record->client
                                ? ClientResource::getUrl('view', ['record' => $record->client])
                                : null),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('practice_area'),
                        TextEntry::make('court_date')
                            ->date()
                            ->placeholder('Not set'),
                        TextEntry::make('court_name')
                            ->placeholder('Not set'),
                        TextEntry::make('assignedUser.name')
                            ->label('Assigned solicitor')
                            ->placeholder('Unassigned'),
                        TextEntry::make('supervisingUser.name')
                            ->label('Supervising user')
                            ->placeholder('None'),
                        TextEntry::make('client.portal_status')
                            ->label('Client portal')
                            ->badge()
                            ->formatStateUsing(fn (PortalStatus $state): string => $state->label())
                            ->color(fn (PortalStatus $state): string => $state->color()),
                    ]),

                Section::make('Compliance checklist')
                    ->components([
                        ViewEntry::make('compliance_checklist')
                            ->hiddenLabel()
                            ->view('filament.matters.compliance-grid'),
                    ]),

                Section::make('Quick stats')
                    ->components([
                        ViewEntry::make('quick_stats')
                            ->hiddenLabel()
                            ->view('filament.matters.quick-stats'),
                    ]),
            ]);
    }

    protected static function caseDetailsTab(): Tab
    {
        return Tab::make('Case Details')
            ->icon(Heroicon::OutlinedScale)
            ->schema([
                Section::make('Case')
                    ->columns(2)
                    ->components([
                        TextEntry::make('urn')
                            ->label('URN')
                            ->placeholder('Not set'),
                        TextEntry::make('title')
                            ->placeholder('Not set'),
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

                Section::make('Source & lead')
                    ->columns(2)
                    ->components([
                        TextEntry::make('source')
                            ->placeholder('Not set'),
                        TextEntry::make('lead.full_name')
                            ->label('Lead')
                            ->placeholder('None')
                            ->url(fn (Matter $record): ?string => $record->lead
                                ? LeadResource::getUrl('view', ['record' => $record->lead])
                                : null),
                        TextEntry::make('lead.gclid')
                            ->label('GCLID')
                            ->placeholder('Not set'),
                        TextEntry::make('lead.campaign_source')
                            ->label('Campaign source')
                            ->placeholder('Not set'),
                    ]),

                Section::make('Key dates')
                    ->columns(3)
                    ->components([
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
            ]);
    }

    protected static function financeTab(): Tab
    {
        return Tab::make('Finance')
            ->icon(Heroicon::OutlinedBanknotes)
            // Same permission, same enforcement point as PaymentPlanResource's
            // own canAccess() — previously a different, looser UI-level check
            // (director/admin/accounts) than the resource itself (director/
            // accounts only); unified on the narrower grant.
            ->visible(fn (): bool => auth()->user()->can('view_finance'))
            ->schema([
                Section::make('Fees')
                    ->components([
                        TextEntry::make('agreed_fee')
                            ->label('Agreed fee')
                            ->money('GBP')
                            ->placeholder('Not set'),
                    ]),

                Section::make('Payment plan')
                    ->components(fn (Matter $record): array => $record->paymentPlan
                        ? [
                            ViewEntry::make('payment_plan_summary')
                                ->hiddenLabel()
                                ->view('filament.matters.payment-plan-summary'),

                            RepeatableEntry::make('paymentPlan.instalments')
                                ->label('Instalments')
                                ->table([
                                    TableColumn::make('Amount'),
                                    TableColumn::make('Due date'),
                                    TableColumn::make('Status'),
                                    TableColumn::make('Paid at'),
                                    TableColumn::make('Days overdue'),
                                ])
                                ->schema([
                                    TextEntry::make('amount')->money('GBP'),
                                    TextEntry::make('due_date')->date(),
                                    TextEntry::make('display_status')
                                        ->label('Status')
                                        ->badge(),
                                    TextEntry::make('paid_at')
                                        ->dateTime()
                                        ->placeholder('—'),
                                    TextEntry::make('days_overdue')
                                        ->label('Days overdue')
                                        ->state(fn (Instalment $record): ?int => $record->daysOverdue() ?: null)
                                        ->placeholder('—'),
                                ]),

                            RepeatableEntry::make('paymentPlan.chaseLogs')
                                ->label('Chase log')
                                ->table([
                                    TableColumn::make('Channel'),
                                    TableColumn::make('Template'),
                                    TableColumn::make('Sent at'),
                                    TableColumn::make('Status'),
                                ])
                                ->schema([
                                    TextEntry::make('channel')->badge(),
                                    TextEntry::make('template'),
                                    TextEntry::make('sent_at')->dateTime(),
                                    TextEntry::make('status')->badge(),
                                ]),
                        ]
                        : [
                            TextEntry::make('no_payment_plan')
                                ->hiddenLabel()
                                ->state('No payment plan set.'),

                            Actions::make([
                                Action::make('createPaymentPlan')
                                    ->label('Create payment plan')
                                    ->icon(Heroicon::OutlinedPlus)
                                    ->url(fn (): string => PaymentPlanResource::getUrl('create')),
                            ]),
                        ]),
            ]);
    }

    protected static function timeTab(): Tab
    {
        return Tab::make('Time')
            ->icon(Heroicon::OutlinedClock)
            ->schema([
                Section::make('Time summary')
                    ->components([
                        ViewEntry::make('time_summary')
                            ->hiddenLabel()
                            ->view('filament.matters.time-summary'),
                    ]),

                Livewire::make(
                    TimeEntryRelationManager::class,
                    fn (Matter $record): array => [
                        'ownerRecord' => $record,
                        'pageClass' => ViewMatter::class,
                        ...TimeEntryRelationManager::getDefaultProperties(),
                    ],
                ),
            ]);
    }

    protected static function documentsTab(): Tab
    {
        return Tab::make('Documents')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                Livewire::make(
                    MatterDocumentsRelationManager::class,
                    fn (Matter $record): array => [
                        'ownerRecord' => $record,
                        'pageClass' => ViewMatter::class,
                        ...MatterDocumentsRelationManager::getDefaultProperties(),
                    ],
                ),

                Section::make('Generated documents')
                    ->components([
                        RepeatableEntry::make('generatedDocuments')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Template'),
                                TableColumn::make('Generated by'),
                                TableColumn::make('Generated at'),
                                TableColumn::make('Download'),
                            ])
                            ->schema([
                                TextEntry::make('precedentTemplate.name')
                                    ->label('Template')
                                    ->placeholder('—'),
                                TextEntry::make('generatedBy.name')
                                    ->label('Generated by')
                                    ->placeholder('Unknown'),
                                TextEntry::make('generated_at')
                                    ->label('Generated at')
                                    ->dateTime(),
                                Actions::make([
                                    Action::make('download')
                                        ->label('Download')
                                        ->icon(Heroicon::OutlinedArrowDownTray)
                                        ->action(fn (GeneratedDocument $record) => Storage::disk('documents')
                                            ->download($record->file_path, $record->filename)),
                                ]),
                            ]),
                    ]),
            ]);
    }

    protected static function communicationsTab(): Tab
    {
        return Tab::make('Communications')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->schema([
                Section::make('Call Notes')
                    ->components([
                        Livewire::make(
                            CallNotesRelationManager::class,
                            fn (Matter $record): array => [
                                'ownerRecord' => $record,
                                'pageClass' => ViewMatter::class,
                                ...CallNotesRelationManager::getDefaultProperties(),
                            ],
                        ),
                    ]),

                Section::make('Messages')
                    ->components([
                        Livewire::make(
                            MatterMessagesRelationManager::class,
                            fn (Matter $record): array => [
                                'ownerRecord' => $record,
                                'pageClass' => ViewMatter::class,
                                ...MatterMessagesRelationManager::getDefaultProperties(),
                            ],
                        ),
                    ]),
            ]);
    }

    protected static function quillTab(): Tab
    {
        return Tab::make('Ask Quill')
            ->icon(Heroicon::OutlinedSparkles)
            ->schema([
                Livewire::make(
                    QuillChat::class,
                    fn (Matter $record): array => ['matter' => $record],
                ),
            ]);
    }

    protected static function notesTab(): Tab
    {
        return Tab::make('Notes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema([
                Section::make('Internal notes')
                    ->components([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->placeholder('Not set')
                            ->columnSpanFull(),
                    ]),

                Section::make('Record flags')
                    ->columns(2)
                    ->components([
                        IconEntry::make('locked')
                            ->boolean(),
                        IconEntry::make('director_only')
                            ->boolean()
                            ->visible(fn (): bool => auth()->user()->hasRole('director')),
                    ]),

                Section::make('Activity')
                    ->components([
                        ViewEntry::make('activity_feed')
                            ->hiddenLabel()
                            ->view('filament.matters.activity-feed'),
                    ]),
            ]);
    }
}
