<?php

namespace App\Filament\Admin\Resources\PaymentPlans\Pages;

use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentPlan extends ViewRecord
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
