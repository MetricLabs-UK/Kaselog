<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Roles\Schemas\RoleForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    private array $pendingPermissions = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(fn (Role $record): string => $record->users()->count() > 0
                    ? "This role is currently held by {$record->users()->count()} user(s) — deleting it removes it from them immediately, along with everything it granted."
                    : 'This action cannot be undone.')
                ->after(fn (Role $record) => RoleForm::logRoleDeleted($record)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...RoleForm::fillPermissionCategories($this->getRecord())];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingPermissions = RoleForm::mergePermissionCategories($data);

        foreach (RoleForm::permissionCategoryKeys() as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $originalName = $record->name;

        $record->update($data);
        $record->syncPermissions($this->pendingPermissions);

        if ($originalName !== $record->name) {
            activity('permissions')
                ->causedBy(auth()->user())
                ->performedOn($record)
                ->tap(fn ($activity) => $activity->tenant_id = $record->tenant_id)
                ->event('role_renamed')
                ->log("Role renamed from \"{$originalName}\" to \"{$record->name}\"");
        }

        return $record;
    }
}
