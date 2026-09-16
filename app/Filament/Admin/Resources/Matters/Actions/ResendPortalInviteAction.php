<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Enums\PortalStatus;
use App\Models\Matter;
use App\Services\PortalInviteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ResendPortalInviteAction
{
    public static function make(): Action
    {
        return Action::make('resendPortalInvite')
            ->label('Resend Portal Invite')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Matter $record): bool => $record->client?->portal_status === PortalStatus::Pending)
            ->requiresConfirmation()
            ->modalDescription('This invalidates the previous invite link and sends the client a new one.')
            ->action(function (Matter $record) {
                try {
                    app(PortalInviteService::class)->invite($record);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Could not resend portal invite')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Portal invite resent')
                    ->success()
                    ->send();
            });
    }
}
