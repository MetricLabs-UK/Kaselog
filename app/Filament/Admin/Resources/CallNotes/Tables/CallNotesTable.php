<?php

namespace App\Filament\Admin\Resources\CallNotes\Tables;

use App\Models\CallNote;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Shared by CallNoteResource (the cross-matter queue) and
 * CallNotesRelationManager (embedded in a Matter's Communications tab) —
 * same columns/actions either way, just a different starting query.
 */
class CallNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('linked_to_label')
                    ->label('Linked to')
                    ->state(fn (?CallNote $record): string => match (true) {
                        $record === null => '—',
                        $record->matter !== null => "Matter — {$record->matter->reference}",
                        $record->client !== null => "Client — {$record->client->full_name}",
                        $record->lead !== null => "Lead — {$record->lead->full_name}",
                        default => 'Unmatched',
                    }),
                TextColumn::make('summary')
                    ->limit(80)
                    ->placeholder('—'),
                IconColumn::make('needs_review')
                    ->label('Needs review')
                    ->boolean(),
                TextColumn::make('review_reason')
                    ->label('Reason')
                    ->limit(60)
                    ->placeholder('—')
                    ->visible(fn (?CallNote $record): bool => $record && ($record->needs_review || filled($record->review_reason))),
                TextColumn::make('reviewedBy.name')
                    ->label('Reviewed by')
                    ->placeholder('—'),
                TextColumn::make('reviewed_at')
                    ->label('Reviewed at')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('needs_review'),
            ])
            ->recordActions([
                ViewAction::make(),
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
            ]);
    }
}
