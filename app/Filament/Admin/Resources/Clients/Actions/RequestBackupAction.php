<?php

namespace App\Filament\Admin\Resources\Clients\Actions;

use App\Enums\BackupDestinationProvider;
use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Enums\BackupExportStatus;
use App\Jobs\GenerateBackupExport;
use App\Models\BackupDestinationConnection;
use App\Models\BackupExport;
use App\Models\Client;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Single-client backup. Phase 1 was direct zip download only; Phase 5 adds
 * a destination choice, but only ever offering a destination that's fully
 * ready (BackupDestinationConnection::hasSiteSelected()) — a connection
 * that's authorized but still awaiting a chosen SharePoint site/Google
 * shared drive never appears here, since pushing to it would just fail.
 * Reused from both the Client's own "Backups" relation manager and the
 * Matter view page — both wire in the resolved Client via ->record().
 */
class RequestBackupAction
{
    public static function make(): Action
    {
        return Action::make('requestBackup')
            ->label('Request Backup')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->visible(fn (): bool => auth()->user()->can('manage_backups'))
            ->modalHeading('Request a backup for this client')
            ->modalDescription("Bundles every document across all of this client's matters, plus a CSV of key matter details, into a single zip file. You'll be emailed once it's ready — this can take a few minutes for clients with many documents.")
            ->schema([
                Select::make('destination')
                    ->label('Send to')
                    ->options(fn (Client $record): array => self::destinationOptions($record->tenant))
                    ->default(BackupExportDestination::Download->value)
                    ->required(),
            ])
            ->action(function (array $data, Client $record): void {
                $export = BackupExport::create([
                    'requested_by_user_id' => auth()->id(),
                    'scope' => BackupExportScope::SingleClient,
                    'client_id' => $record->id,
                    'destination' => BackupExportDestination::from($data['destination']),
                    'status' => BackupExportStatus::Pending,
                ]);

                GenerateBackupExport::dispatch($export->id);

                Notification::make()
                    ->title('Backup requested')
                    ->body("We'll email you once it's ready.")
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function destinationOptions(Tenant $tenant): array
    {
        $options = [
            BackupExportDestination::Download->value => 'Direct zip download',
        ];

        foreach (BackupDestinationProvider::cases() as $provider) {
            $connection = BackupDestinationConnection::forTenantAndProvider($tenant, $provider);

            if ($connection?->hasSiteSelected()) {
                $options[$provider->value] = "Push to {$provider->getLabel()} (\"{$connection->site_name}\")";
            }
        }

        return $options;
    }
}
