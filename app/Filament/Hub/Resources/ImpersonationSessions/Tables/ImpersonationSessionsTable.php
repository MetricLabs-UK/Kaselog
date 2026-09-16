<?php

namespace App\Filament\Hub\Resources\ImpersonationSessions\Tables;

use App\Enums\ImpersonationStatus;
use App\Models\ImpersonationSession;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImpersonationSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Firm'),
                TextColumn::make('targetUser.name')
                    ->label('User'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('reason')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('requested_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('responded_at')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('session_expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('enterSession')
                    ->label('Enter Session')
                    ->color('success')
                    ->visible(fn (ImpersonationSession $record): bool => $record->status === ImpersonationStatus::Accepted
                        && ! $record->isAcceptedWindowExpired())
                    ->url(fn (ImpersonationSession $record): string => route('impersonation.enter', $record)),
                Action::make('cancel')
                    ->label('Cancel Request')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ImpersonationSession $record): bool => $record->status === ImpersonationStatus::Pending)
                    ->action(function (ImpersonationSession $record): void {
                        $record->end(auth()->user(), 'director_cancelled');

                        Notification::make()->title('Request cancelled')->success()->send();
                    }),
            ]);
    }
}
