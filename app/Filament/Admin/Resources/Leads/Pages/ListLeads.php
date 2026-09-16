<?php

namespace App\Filament\Admin\Resources\Leads\Pages;

use App\Filament\Admin\Resources\Leads\LeadResource;
use App\Filament\Concerns\HasArchivedTab;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListLeads extends ListRecords
{
    use HasArchivedTab;

    protected static string $resource = LeadResource::class;

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
