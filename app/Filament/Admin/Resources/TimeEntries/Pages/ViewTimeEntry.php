<?php

namespace App\Filament\Admin\Resources\TimeEntries\Pages;

use App\Filament\Admin\Resources\TimeEntries\TimeEntryResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTimeEntry extends ViewRecord
{
    protected static string $resource = TimeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
