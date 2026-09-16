<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Support\ArchiveActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditMatter extends EditRecord
{
    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ArchiveActions::archive('archive_matters'),
            ArchiveActions::restore('archive_matters'),
            DeleteAction::make(),
        ];
    }
}
