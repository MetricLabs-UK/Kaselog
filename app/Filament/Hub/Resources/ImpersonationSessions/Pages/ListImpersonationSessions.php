<?php

namespace App\Filament\Hub\Resources\ImpersonationSessions\Pages;

use App\Filament\Hub\Resources\ImpersonationSessions\ImpersonationSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListImpersonationSessions extends ListRecords
{
    protected static string $resource = ImpersonationSessionResource::class;
}
