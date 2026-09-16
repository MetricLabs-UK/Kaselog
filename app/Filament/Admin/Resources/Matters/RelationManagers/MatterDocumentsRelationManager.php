<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Enums\DocumentAiSummaryStatus;
use App\Filament\Admin\Resources\Matters\Actions\GenerateDocumentAction;
use App\Jobs\SummarizeMatterDocument;
use App\Models\DocumentAiSummary;
use App\Models\Matter;
use App\Models\MatterDocument;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class MatterDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('filename')
            ->poll('4s')
            ->columns([
                TextColumn::make('filename')
                    ->searchable(),
                TextColumn::make('uploaded_by_label')
                    ->label('Uploaded by'),
                IconColumn::make('visible_to_client')
                    ->boolean(),
                TextColumn::make('aiSummary.status')
                    ->label('AI summary')
                    ->badge()
                    ->visible(fn (?MatterDocument $record): bool => $record?->isPdf() ?? false)
                    ->state(function (MatterDocument $record): ?DocumentAiSummaryStatus {
                        return $record->aiSummary?->status;
                    })
                    ->formatStateUsing(function (?DocumentAiSummaryStatus $state, MatterDocument $record): string {
                        // A needs_review row is still Completed underneath —
                        // this only overrides the label shown, not the real
                        // status the retry/view actions gate on.
                        if ($state === DocumentAiSummaryStatus::Completed && $record->aiSummary?->needs_review && ! $record->aiSummary->reviewed_at) {
                            return 'Needs review';
                        }

                        return match ($state) {
                            DocumentAiSummaryStatus::Completed => 'Ready',
                            DocumentAiSummaryStatus::Failed => 'Failed',
                            default => 'Processing…',
                        };
                    })
                    ->color(function (?DocumentAiSummaryStatus $state, MatterDocument $record): string {
                        if ($state === DocumentAiSummaryStatus::Completed && $record->aiSummary?->needs_review && ! $record->aiSummary->reviewed_at) {
                            return 'warning';
                        }

                        return match ($state) {
                            DocumentAiSummaryStatus::Completed => 'success',
                            DocumentAiSummaryStatus::Failed => 'danger',
                            default => 'warning',
                        };
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label('Upload Document')
                    ->form([
                        FileUpload::make('path')
                            ->label('File')
                            ->required()
                            ->disk('documents')
                            ->directory(fn (): string => 'matter-docs/'.$this->getOwnerRecord()->id)
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'image/jpeg',
                                'image/png',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->storeFileNamesIn('filename')
                            ->visibility('private'),
                        Toggle::make('visible_to_client'),
                    ])
                    ->action(function (array $data): void {
                        $document = MatterDocument::create([
                            'matter_id' => $this->getOwnerRecord()->id,
                            'uploaded_by_type' => 'user',
                            'uploaded_by_id' => auth()->id(),
                            'filename' => $data['filename'],
                            'path' => $data['path'],
                            'visible_to_client' => $data['visible_to_client'] ?? false,
                        ]);

                        if ($document->isPdf()) {
                            $this->dispatchAiSummary($document);
                        }
                    }),
                GenerateDocumentAction::make()
                    ->record(fn (): Matter => $this->getOwnerRecord()),
            ])
            ->recordActions([
                Action::make('toggleVisibleToClient')
                    ->label(fn (MatterDocument $record): string => $record->visible_to_client ? 'Hide from client' : 'Show to client')
                    ->icon(fn (MatterDocument $record) => $record->visible_to_client
                        ? Heroicon::OutlinedEyeSlash
                        : Heroicon::OutlinedEye)
                    ->visible(fn (): bool => auth()->user()->can('edit_matters'))
                    ->action(fn (MatterDocument $record) => $record->update([
                        'visible_to_client' => ! $record->visible_to_client,
                    ])),
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (MatterDocument $record) => Storage::disk('documents')->download($record->path, $record->filename)),
                Action::make('viewAiSummary')
                    ->label('View AI Summary')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->visible(fn (MatterDocument $record): bool => $record->aiSummary?->status === DocumentAiSummaryStatus::Completed)
                    ->modalHeading('AI summary')
                    ->modalContent(fn (MatterDocument $record) => view('filament.matters.ai-summary-modal', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('markAiSummaryReviewed')
                    ->label('Mark Reviewed')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('This only dismisses the flag — it never updates the CRM record itself. Edit the client/matter directly if the document is correct.')
                    ->visible(fn (MatterDocument $record): bool => ($record->aiSummary?->needs_review ?? false) && ! $record->aiSummary?->reviewed_at)
                    ->action(function (MatterDocument $record): void {
                        $record->aiSummary->markReviewed(auth()->user());

                        Notification::make()->title('Marked reviewed')->success()->send();
                    }),
                Action::make('retryAiSummary')
                    ->label('Retry AI Summary')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (MatterDocument $record): bool => $record->aiSummary?->status === DocumentAiSummaryStatus::Failed)
                    ->action(function (MatterDocument $record): void {
                        $this->dispatchAiSummary($record);

                        Notification::make()
                            ->title('AI summary queued again')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('delete_matters')),
            ]);
    }

    /**
     * Shared by the initial upload and the "retry" action — the AI summary
     * row is (re)set to Pending synchronously, before the job is dispatched,
     * so the table's poll() has something correct to show immediately
     * rather than a gap where the document looks like it has no AI summary
     * at all until a queue worker picks the job up.
     */
    private function dispatchAiSummary(MatterDocument $document): void
    {
        $summary = $document->aiSummary;

        if ($summary) {
            $summary->update([
                'status' => DocumentAiSummaryStatus::Pending,
                'error_message' => null,
            ]);
        } else {
            DocumentAiSummary::create([
                'matter_document_id' => $document->id,
                'status' => DocumentAiSummaryStatus::Pending,
            ]);
        }

        SummarizeMatterDocument::dispatch($document->id);
    }
}
