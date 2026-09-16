<?php

namespace App\Filament\Hub\Resources\Tenants\Pages;

use App\Filament\Hub\Resources\Tenants\TenantResource;
use App\Support\Hub\HubAccess;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('billing')
                ->label('Billing')
                ->icon(Heroicon::OutlinedCurrencyPound)
                ->visible(fn (): bool => auth()->user()->hasHubPermission(HubAccess::PERMISSION_MANAGE_BILLING))
                ->url(fn () => ManageTenantBilling::getUrl(['record' => $this->getRecord()])),
        ];
    }
}
