<?php

namespace App\Filament\Admin\Resources\TimeEntries\Pages;

use App\Filament\Admin\Resources\TimeEntries\TimeEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTimeEntry extends CreateRecord
{
    protected static string $resource = TimeEntryResource::class;
}
