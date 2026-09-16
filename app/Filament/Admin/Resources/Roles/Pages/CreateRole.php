<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Roles\Schemas\RoleForm;
use App\Support\Tenancy\CurrentTenant;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    private array $pendingPermissions = [];

    /**
     * name/guard_name/tenant_id are the only real columns on Role — the
     * permissions_{category} fields are virtual (see RoleForm), stashed
     * here and applied via syncPermissions() in afterCreate() instead of
     * mass-assignment, since spatie's own syncPermissions() is what
     * correctly manages the pivot table (and fires the PermissionAttachedEvent
     * LogPermissionActivity already listens for — see its own docblock).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingPermissions = RoleForm::mergePermissionCategories($data);

        foreach (RoleForm::permissionCategoryKeys() as $key) {
            unset($data[$key]);
        }

        $data['guard_name'] = 'web';
        $data['tenant_id'] = CurrentTenant::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncPermissions($this->pendingPermissions);

        activity('permissions')
            ->causedBy(auth()->user())
            ->performedOn($this->record)
            ->tap(fn ($activity) => $activity->tenant_id = $this->record->tenant_id)
            ->event('role_created')
            ->log("Role \"{$this->record->name}\" created");
    }
}
