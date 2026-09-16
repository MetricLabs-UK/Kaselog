<?php

namespace App\Filament\Admin\Resources\PrecedentTemplates\Pages;

use App\Filament\Admin\Resources\PrecedentTemplates\PrecedentTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrecedentTemplates extends ListRecords
{
    protected static string $resource = PrecedentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
