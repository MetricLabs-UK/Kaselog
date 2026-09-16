<?php

namespace App\Filament\Admin\Resources\Leads\Pages;

use App\Filament\Admin\Resources\Leads\LeadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;
}
