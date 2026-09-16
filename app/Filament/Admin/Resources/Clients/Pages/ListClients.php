<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Concerns\HasArchivedTab;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListClients extends ListRecords
{
    use HasArchivedTab;

    protected static string $resource = ClientResource::class;

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
