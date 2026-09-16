<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Filament\Admin\Resources\Clients\Actions\RequestBackupAction;
use App\Filament\Admin\Resources\Matters\Actions\AccessToPortalAction;
use App\Filament\Admin\Resources\Matters\Actions\ResendPortalInviteAction;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Schemas\MatterViewTabs;
use App\Models\Client;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewMatter extends ViewRecord
{
    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AccessToPortalAction::make(),
            ResendPortalInviteAction::make(),
            RequestBackupAction::make()
                ->record(fn (): Client => $this->getRecord()->client),
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return MatterViewTabs::configure($schema);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getInfolistContentComponent(),
            ]);
    }
}
