<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Archive/Restore actions for any model using App\Models\Concerns\
 * Archivable — Matter, Client, Lead, PaymentPlan. $permission is one of the
 * new archive_matters/archive_clients/archive_leads/archive_payment_plans
 * grants (see TenantRoleSeeder) — deliberately not that record type's
 * delete_* permission, which still governs child-record deletion within it
 * and is unrelated to archiving the record itself.
 */
class ArchiveActions
{
    public static function archive(string $permission): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Archived records are hidden from the default list, but nothing is deleted — they can be restored at any time.')
            ->visible(fn (Model $record): bool => ! $record->isArchived() && auth()->user()->can($permission))
            ->action(function (Model $record): void {
                $record->archive(auth()->user());

                Notification::make()
                    ->title('Archived')
                    ->success()
                    ->send();
            });
    }

    public static function restore(string $permission): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Model $record): bool => $record->isArchived() && auth()->user()->can($permission))
            ->action(function (Model $record): void {
                $record->restore();

                Notification::make()
                    ->title('Restored')
                    ->success()
                    ->send();
            });
    }

    public static function bulkArchive(string $permission): BulkAction
    {
        return BulkAction::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Archived records are hidden from the default list, but nothing is deleted — they can be restored at any time.')
            ->visible(fn (): bool => auth()->user()->can($permission))
            ->action(function (Collection $records): void {
                $records->each(fn (Model $record) => $record->archive(auth()->user()));
            })
            ->deselectRecordsAfterCompletion();
    }
}
