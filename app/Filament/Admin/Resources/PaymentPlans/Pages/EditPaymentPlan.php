<?php

namespace App\Filament\Admin\Resources\PaymentPlans\Pages;

use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Filament\Support\ArchiveActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentPlan extends EditRecord
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ArchiveActions::archive('archive_payment_plans'),
            ArchiveActions::restore('archive_payment_plans'),
            DeleteAction::make(),
        ];
    }
}
