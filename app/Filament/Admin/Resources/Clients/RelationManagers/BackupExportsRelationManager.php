<?php

namespace App\Filament\Admin\Resources\Clients\RelationManagers;

use App\Filament\Admin\Resources\Clients\Actions\RequestBackupAction;
use App\Models\Client;
use App\Support\Backups\BackupExportTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Section 15-adjacent firm-facing backup (Phase 1) — one row per export this
 * client has had requested, newest first, with a download link once ready.
 * Read-only otherwise: no create/edit form, exports only ever come from
 * RequestBackupAction or (later) the whole-firm/daily-push flows.
 */
class BackupExportsRelationManager extends RelationManager
{
    protected static string $relationship = 'backupExports';

    protected static ?string $title = 'Backups';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('manage_backups');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns(BackupExportTable::columns())
            ->headerActions([
                RequestBackupAction::make()
                    ->record(fn (): Client => $this->getOwnerRecord()),
            ])
            ->recordActions([
                BackupExportTable::downloadAction(),
            ]);
    }
}
