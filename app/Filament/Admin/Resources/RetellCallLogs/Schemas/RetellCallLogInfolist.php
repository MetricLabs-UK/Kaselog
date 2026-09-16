<?php

namespace App\Filament\Admin\Resources\RetellCallLogs\Schemas;

use App\Filament\Admin\Resources\CallNotes\CallNoteResource;
use App\Filament\Admin\Resources\RetellCallLogs\Tables\RetellCallLogsTable;
use App\Models\RetellCallLog;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RetellCallLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Call')
                    ->columns(3)
                    ->components([
                        TextEntry::make('created_at')
                            ->label('When')
                            ->dateTime(),
                        TextEntry::make('caller')
                            ->state(fn (RetellCallLog $record): string => $record->callerName()
                                ?: ($record->callerPhoneNumber() ?: 'Unknown caller')),
                        TextEntry::make('call_type')
                            ->label('Call type')
                            ->state(fn (RetellCallLog $record): string => $record->callType() ?? '—'),
                        TextEntry::make('duration')
                            ->state(fn (RetellCallLog $record): string => RetellCallLogsTable::formatDuration($record->durationSeconds())),
                        TextEntry::make('call_status')
                            ->label('Status')
                            ->state(fn (RetellCallLog $record): string => $record->callStatus() ?? '—'),
                        TextEntry::make('disconnection_reason')
                            ->label('Ended because')
                            ->state(fn (RetellCallLog $record): string => $record->disconnectionReason() ?? '—'),
                        TextEntry::make('call_id')
                            ->label('Retell call ID')
                            ->copyable(),
                        TextEntry::make('recording')
                            ->label('Recording')
                            ->state(fn (RetellCallLog $record): string => $record->recordingUrl() ? 'Available' : 'Not available')
                            ->url(fn (RetellCallLog $record): ?string => $record->recordingUrl())
                            ->openUrlInNewTab(),
                    ]),

                Section::make('Outcome')
                    ->columns(3)
                    ->components([
                        TextEntry::make('matched_to')
                            ->label('Matched to')
                            ->state(fn (RetellCallLog $record) => RetellCallLogsTable::matchedToLabel($record->callNote))
                            ->url(fn (RetellCallLog $record): ?string => RetellCallLogsTable::matchedToUrl($record->callNote)),
                        TextEntry::make('outcome')
                            ->badge()
                            ->state(fn (RetellCallLog $record): string => RetellCallLogsTable::outcomeLabel($record->callNote))
                            ->color(fn (RetellCallLog $record): string => RetellCallLogsTable::outcomeColor($record->callNote)),
                        TextEntry::make('review_reason')
                            ->label('Review reason')
                            ->state(fn (RetellCallLog $record): string => $record->callNote?->review_reason ?? '—')
                            ->columnSpanFull(),
                        TextEntry::make('view_call_note')
                            ->label('Call note')
                            ->state(fn (RetellCallLog $record): string => $record->callNote ? 'Open the matched call note' : 'No call note exists for this call yet.')
                            ->url(fn (RetellCallLog $record): ?string => $record->callNote
                                ? CallNoteResource::getUrl('view', ['record' => $record->callNote])
                                : null)
                            ->columnSpanFull(),
                    ]),

                Section::make('Analysis')
                    ->columns(2)
                    ->components([
                        TextEntry::make('user_sentiment')
                            ->label('Caller sentiment')
                            ->state(fn (RetellCallLog $record): string => $record->userSentiment() ?? '—'),
                        TextEntry::make('call_successful')
                            ->label('Call successful')
                            ->state(fn (RetellCallLog $record): string => match ($record->callSuccessful()) {
                                true => 'Yes',
                                false => 'No',
                                default => '—',
                            }),
                        TextEntry::make('call_summary')
                            ->label('Summary')
                            ->state(fn (RetellCallLog $record): string => $record->callSummary() ?? 'No AI summary available.')
                            ->columnSpanFull()
                            ->prose(),
                    ]),

                Section::make('Transcript')
                    ->components([
                        TextEntry::make('transcript')
                            ->hiddenLabel()
                            ->state(fn (RetellCallLog $record): string => $record->transcript() ?? 'No transcript available.')
                            ->prose()
                            ->columnSpanFull(),
                    ]),

                Section::make('Event history')
                    ->description('Every webhook delivery Retell sent for this call.')
                    ->components([
                        RepeatableEntry::make('siblingLogs')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('event_type')
                                    ->label('Event')
                                    ->badge(),
                                TextEntry::make('created_at')
                                    ->label('Received')
                                    ->dateTime(),
                                TextEntry::make('processed_at')
                                    ->dateTime()
                                    ->placeholder('Not processed'),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
