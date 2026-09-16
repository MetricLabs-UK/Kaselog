<?php

namespace App\Filament\Admin\Resources\PrecedentTemplates\Pages;

use App\Filament\Admin\Resources\PrecedentTemplates\PrecedentTemplateResource;
use App\Filament\Concerns\RequiresChangeReasonOnEdit;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPrecedentTemplate extends EditRecord
{
    use RequiresChangeReasonOnEdit;

    protected static string $resource = PrecedentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
