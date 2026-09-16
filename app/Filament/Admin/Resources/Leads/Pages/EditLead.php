<?php

namespace App\Filament\Admin\Resources\Leads\Pages;

use App\Filament\Admin\Resources\Leads\LeadResource;
use App\Filament\Support\ArchiveActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ArchiveActions::archive('archive_leads'),
            ArchiveActions::restore('archive_leads'),
            DeleteAction::make(),
        ];
    }
}
