<?php

namespace App\Filament\Admin\Resources\PrecedentTemplates\Pages;

use App\Filament\Admin\Resources\PrecedentTemplates\PrecedentTemplateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPrecedentTemplate extends ViewRecord
{
    protected static string $resource = PrecedentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
