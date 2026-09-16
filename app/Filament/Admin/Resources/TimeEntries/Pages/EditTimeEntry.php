<?php

namespace App\Filament\Admin\Resources\TimeEntries\Pages;

use App\Filament\Admin\Resources\TimeEntries\TimeEntryResource;
use App\Filament\Concerns\RequiresChangeReasonOnEdit;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTimeEntry extends EditRecord
{
    use RequiresChangeReasonOnEdit;

    protected static string $resource = TimeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
