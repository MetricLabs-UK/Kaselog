<?php

namespace App\Filament\Hub\Resources\PrecedentTemplateLibrary\Pages;

use App\Filament\Hub\Resources\PrecedentTemplateLibrary\PrecedentTemplateLibraryResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrecedentTemplateLibraryItem extends CreateRecord
{
    protected static string $resource = PrecedentTemplateLibraryResource::class;

    /**
     * is_master is never a form field — every row this resource creates is
     * a master by definition. available_fields defaults to [] for rich_text
     * masters, since the "Available Fields" repeater is hidden (and so
     * never dehydrates) for that type, but the column is NOT NULL.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_master'] = true;
        $data['available_fields'] ??= [];

        return $data;
    }
}
