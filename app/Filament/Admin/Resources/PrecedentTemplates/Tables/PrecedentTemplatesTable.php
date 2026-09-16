<?php

namespace App\Filament\Admin\Resources\PrecedentTemplates\Tables;

use App\Enums\PrecedentTemplateType;
use App\Models\Matter;
use App\Models\PrecedentTemplate;
use App\Models\PrecedentTemplateFolder;
use App\Services\DocumentGenerationService;
use App\Services\PrecedentTemplateAdoptionService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PrecedentTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('template_key')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (PrecedentTemplateType $state): string => match ($state) {
                        PrecedentTemplateType::DocxUpload => 'Docx upload',
                        PrecedentTemplateType::RichText => 'Rich text',
                    }),
                TextColumn::make('folder.name')
                    ->label('Folder')
                    ->placeholder('—'),
                TextColumn::make('available_fields')
                    ->label('Fields')
                    ->state(fn (PrecedentTemplate $record): int => count($record->available_fields ?? [])),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->placeholder('Unknown'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('adoptFromLibrary')
                    ->label('Adopt from library')
                    ->icon(Heroicon::OutlinedArrowDownOnSquare)
                    ->schema([
                        Select::make('master_id')
                            ->label('Master template')
                            ->options(fn (): array => self::adoptableMasterOptions())
                            ->searchable()
                            ->required()
                            ->helperText('Already-adopted masters are not shown again — edit your existing copy instead.'),
                        Select::make('folder_id')
                            ->label('Folder')
                            ->relationship('folder', 'name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')->required()->maxLength(255),
                            ])
                            ->nullable(),
                    ])
                    ->action(function (array $data): void {
                        $master = PrecedentTemplate::allTenants()->where('is_master', true)->findOrFail($data['master_id']);
                        $folder = filled($data['folder_id'] ?? null)
                            ? PrecedentTemplateFolder::find($data['folder_id'])
                            : null;

                        app(PrecedentTemplateAdoptionService::class)->adopt($master, $folder);

                        Notification::make()
                            ->title('Template adopted')
                            ->body('Your own editable copy has been created.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('preview')
                    ->label('Preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->visible(fn (PrecedentTemplate $record): bool => $record->type === PrecedentTemplateType::RichText)
                    ->modalWidth('4xl')
                    ->schema([
                        Select::make('matter_id')
                            ->label('Preview with matter')
                            ->options(fn (): array => Matter::query()
                                ->with('client')
                                ->get()
                                ->mapWithKeys(fn (Matter $matter): array => [$matter->id => trim("{$matter->reference} — {$matter->client?->full_name}")])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),
                        TextEntry::make('rendered_preview')
                            ->hiddenLabel()
                            ->html()
                            ->state(function (Get $get, PrecedentTemplate $record): HtmlString {
                                $matter = filled($get('matter_id')) ? Matter::find($get('matter_id')) : null;

                                if (! $matter) {
                                    return new HtmlString('<em>Pick a matter above to preview this template with its real data. No document is generated by this preview.</em>');
                                }

                                return new HtmlString(app(DocumentGenerationService::class)->renderRichTextHtml($record, $matter));
                            }),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()->can('delete_precedent_templates')),
                ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function adoptableMasterOptions(): array
    {
        $adoptedMasterIds = PrecedentTemplate::query()
            ->whereNotNull('adopted_from_id')
            ->pluck('adopted_from_id');

        return PrecedentTemplate::allTenants()
            ->where('is_master', true)
            ->where('active', true)
            ->whereNotIn('id', $adoptedMasterIds)
            ->pluck('name', 'id')
            ->all();
    }
}
