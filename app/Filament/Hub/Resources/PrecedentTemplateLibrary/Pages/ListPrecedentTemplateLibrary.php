<?php

namespace App\Filament\Hub\Resources\PrecedentTemplateLibrary\Pages;

use App\Filament\Hub\Resources\PrecedentTemplateLibrary\PrecedentTemplateLibraryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrecedentTemplateLibrary extends ListRecords
{
    protected static string $resource = PrecedentTemplateLibraryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
