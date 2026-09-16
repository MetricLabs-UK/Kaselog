<?php

namespace App\Filament\Admin\Resources\Matters\Actions;

use App\Enums\PortalStatus;
use App\Models\Matter;
use App\Services\PortalInviteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class AccessToPortalAction
{
    public static function make(): Action
    {
        return Action::make('accessToPortal')
            ->label('Access to Portal')
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (Matter $record): bool => $record->client?->portal_status === PortalStatus::NotInvited)
            ->requiresConfirmation()
            ->modalDescription('This creates the client\'s portal account and emails them a link to set their own password.')
            ->action(function (Matter $record) {
                try {
                    app(PortalInviteService::class)->invite($record);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Could not send portal invite')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Portal invite sent')
                    ->success()
                    ->send();
            });
    }
}
