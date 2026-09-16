<?php

namespace App\Filament\Hub\Resources\Tenants\Pages;

use App\Filament\Hub\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
}
