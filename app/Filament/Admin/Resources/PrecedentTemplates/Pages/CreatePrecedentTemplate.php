<?php

namespace App\Filament\Admin\Resources\PrecedentTemplates\Pages;

use App\Filament\Admin\Resources\PrecedentTemplates\PrecedentTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrecedentTemplate extends CreateRecord
{
    protected static string $resource = PrecedentTemplateResource::class;

    /**
     * The "Available Fields" repeater is hidden entirely for rich_text
     * templates (merge tags live inline in the content instead), so it
     * never dehydrates — available_fields is a NOT NULL json column, so it
     * still needs a value.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['available_fields'] ??= [];

        return $data;
    }
}
