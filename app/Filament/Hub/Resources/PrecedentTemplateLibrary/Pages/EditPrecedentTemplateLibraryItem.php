<?php

namespace App\Filament\Hub\Resources\PrecedentTemplateLibrary\Pages;

use App\Filament\Hub\Resources\PrecedentTemplateLibrary\PrecedentTemplateLibraryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrecedentTemplateLibraryItem extends EditRecord
{
    protected static string $resource = PrecedentTemplateLibraryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
