<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Models\Matter;
use App\Models\PrecedentTemplate;
use App\Services\DocumentGenerationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateDocumentAction
{
    public static function make(): Action
    {
        return Action::make('generateDocument')
            ->label('Generate Document')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                Select::make('precedent_template_id')
                    ->label('Template')
                    ->options(fn (): array => PrecedentTemplate::query()
                        ->where('active', true)
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->live()
                    ->required(),
                Placeholder::make('available_fields_preview')
                    ->label('Available fields')
                    ->content(function (Get $get): string {
                        $templateId = $get('precedent_template_id');

                        if (blank($templateId)) {
                            return '—';
                        }

                        $fields = PrecedentTemplate::find($templateId)?->available_fields ?? [];

                        return $fields ? implode(', ', $fields) : '—';
                    }),
            ])
            ->action(function (array $data, Matter $record) {
                $template = PrecedentTemplate::findOrFail($data['precedent_template_id']);

                try {
                    $document = app(DocumentGenerationService::class)->generate($record, $template);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Document generation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                Notification::make()
                    ->title('Document generated')
                    ->success()
                    ->send();

                return Storage::disk('documents')->download($document->file_path, $document->filename);
            });
    }
}
