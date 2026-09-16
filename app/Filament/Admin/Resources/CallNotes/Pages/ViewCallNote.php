<?php

namespace App\Filament\Admin\Resources\CallNotes\Pages;

use App\Filament\Admin\Resources\CallNotes\CallNoteResource;
use App\Models\CallNote;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCallNote extends ViewRecord
{
    protected static string $resource = CallNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markReviewed')
                ->label('Mark Reviewed')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (CallNote $record): bool => $record->needs_review
                    && $record->reviewed_at === null
                    && auth()->user()->can('review_call_notes'))
                ->action(function (CallNote $record): void {
                    $record->markReviewed(auth()->user());

                    Notification::make()
                        ->title('Marked reviewed')
                        ->success()
                        ->send();
                }),
        ];
    }
}
