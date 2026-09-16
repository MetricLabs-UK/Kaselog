<?php

namespace App\Support\Backups;

use App\Enums\BackupExportDestination;
use App\Enums\BackupExportStatus;
use App\Models\BackupExport;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Shared between BackupExportsRelationManager (one client's own history) and
 * BackupIntegration (every export the firm has ever requested) — same
 * columns and download action either way, just a different base query.
 */
final class BackupExportTable
{
    /**
     * @return array<int, TextColumn>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('destination')
                ->badge()
                ->formatStateUsing(fn (BackupExportDestination $state): string => match ($state) {
                    BackupExportDestination::Download => 'Zip download',
                    BackupExportDestination::SharePoint => 'SharePoint',
                    BackupExportDestination::GoogleDrive => 'Google Drive',
                }),
            TextColumn::make('status')
                ->badge()
                ->color(fn (BackupExportStatus $state): string => match ($state) {
                    BackupExportStatus::Pending, BackupExportStatus::Processing => 'warning',
                    BackupExportStatus::Completed => 'success',
                    BackupExportStatus::Failed => 'danger',
                }),
            TextColumn::make('file_size')
                ->label('Size')
                ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : '—'),
            TextColumn::make('requestedBy.name')
                ->label('Requested by')
                ->placeholder('System'),
            TextColumn::make('created_at')
                ->label('Requested')
                ->dateTime(),
            TextColumn::make('expires_at')
                ->label('Available until')
                ->dateTime()
                ->placeholder('—'),
        ];
    }

    public static function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Download')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (BackupExport $record): bool => $record->isDownloadable())
            ->action(fn (BackupExport $record) => Storage::disk('exports')->download($record->file_path, "backup-{$record->id}.zip"));
    }
}
