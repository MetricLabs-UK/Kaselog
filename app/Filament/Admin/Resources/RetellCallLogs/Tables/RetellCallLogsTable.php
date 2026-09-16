<?php

namespace App\Filament\Admin\Resources\RetellCallLogs\Tables;

use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Leads\LeadResource;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\CallNote;
use App\Models\RetellCallLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RetellCallLogsTable
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
                TextColumn::make('caller')
                    ->state(fn (RetellCallLog $record): string => $record->callerName()
                        ?: ($record->callerPhoneNumber() ?: 'Unknown caller')),
                TextColumn::make('matched_to')
                    ->label('Matched to')
                    ->state(fn (RetellCallLog $record) => self::matchedToLabel($record->callNote))
                    ->url(fn (RetellCallLog $record): ?string => self::matchedToUrl($record->callNote)),
                TextColumn::make('outcome')
                    ->badge()
                    ->state(fn (RetellCallLog $record): string => self::outcomeLabel($record->callNote))
                    ->color(fn (RetellCallLog $record): string => self::outcomeColor($record->callNote)),
                TextColumn::make('duration')
                    ->state(fn (RetellCallLog $record): string => self::formatDuration($record->durationSeconds()))
                    ->placeholder('—'),
                TextColumn::make('call_id')
                    ->label('Call ID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function matchedToLabel(?CallNote $callNote): string
    {
        return match (true) {
            $callNote === null => '—',
            filled($callNote->matter_id) => "Matter {$callNote->matter->reference}",
            filled($callNote->client_id) => "Client — {$callNote->client->full_name}",
            filled($callNote->lead_id) => "Lead — {$callNote->lead->full_name}",
            default => 'Unmatched',
        };
    }

    public static function matchedToUrl(?CallNote $callNote): ?string
    {
        return match (true) {
            $callNote === null => null,
            filled($callNote->matter_id) => MatterResource::getUrl('view', ['record' => $callNote->matter_id]),
            filled($callNote->client_id) => ClientResource::getUrl('view', ['record' => $callNote->client_id]),
            filled($callNote->lead_id) => LeadResource::getUrl('view', ['record' => $callNote->lead_id]),
            default => null,
        };
    }

    public static function outcomeLabel(?CallNote $callNote): string
    {
        return match (true) {
            $callNote === null => 'Not yet analysed',
            $callNote->needs_review => 'Needs review',
            filled($callNote->matter_id), filled($callNote->client_id) => 'Matched',
            filled($callNote->lead_id) => 'New lead',
            default => 'Unmatched',
        };
    }

    public static function outcomeColor(?CallNote $callNote): string
    {
        return match (true) {
            $callNote === null => 'gray',
            $callNote->needs_review => 'warning',
            default => 'success',
        };
    }

    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
