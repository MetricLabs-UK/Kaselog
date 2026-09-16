<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Support\ArchiveActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ArchiveActions::archive('archive_clients'),
            ArchiveActions::restore('archive_clients'),
            DeleteAction::make(),
        ];
    }
}
