<?php

namespace App\Filament\Admin\Resources\PaymentPlans\Pages;

use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Filament\Concerns\HasArchivedTab;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListPaymentPlans extends ListRecords
{
    use HasArchivedTab;

    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $tabs = [
            'current' => Tab::make('Current'),
        ];

        if (auth()->user()->hasRole('director')) {
            $tabs['archived'] = static::archivedTab();
        }

        return $tabs;
    }
}
