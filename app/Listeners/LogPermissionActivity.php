<?php

namespace App\Listeners;

use App\Support\Hub\HubAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Covers both directions spatie/laravel-permission fires these for: a
 * user/role being granted/revoked a role (RoleAttached/DetachedEvent — e.g.
 * firm staff role assignment, or a future Hub director reassigning
 * hub_director), and a role having its own permissions edited
 * (Permission*Event, $event->model is the Role — e.g. TenantRoleSeeder today,
 * the future per-tenant role-management UI later). Requires
 * config('permission.events_enabled'), off by default in the package.
 */
class LogPermissionActivity
{
    public function handleRoleAttached(RoleAttachedEvent $event): void
    {
        $this->log('role_attached', 'Role(s) granted', $event->model, $event->rolesOrIds, RoleModel::class);
    }

    public function handleRoleDetached(RoleDetachedEvent $event): void
    {
        $this->log('role_detached', 'Role(s) revoked', $event->model, $event->rolesOrIds, RoleModel::class);
    }

    public function handlePermissionAttached(PermissionAttachedEvent $event): void
    {
        $this->log('permission_attached', 'Permission(s) granted', $event->model, $event->permissionsOrIds, PermissionModel::class);
    }

    public function handlePermissionDetached(PermissionDetachedEvent $event): void
    {
        $this->log('permission_detached', 'Permission(s) revoked', $event->model, $event->permissionsOrIds, PermissionModel::class);
    }

    private function log(string $event, string $description, Model $target, mixed $items, string $lookupClass): void
    {
        $names = $this->resolveNames($items, $lookupClass);

        if ($names === []) {
            return;
        }

        activity('permissions')
            ->causedBy(auth()->user())
            ->performedOn($target)
            ->withProperties([
                'names' => $names,
                'target_type' => $target::class,
                'target_label' => $target->name ?? $target->email ?? null,
            ])
            ->tap(function (Activity $activity) use ($target): void {
                $activity->tenant_id = $this->resolveTenantId($target);
            })
            ->event($event)
            ->log($description);
    }

    /**
     * $rolesOrIds/$permissionsOrIds are already normalised to an array of
     * primary keys by the time HasRoles/HasPermissions fire these events
     * (collectRoles()/collectPermissions() run first) — the model-instance
     * case is handled defensively in case that ever changes upstream.
     *
     * @return array<int, string>
     */
    private function resolveNames(mixed $items, string $lookupClass): array
    {
        $items = match (true) {
            $items instanceof Collection => $items->all(),
            is_array($items) => $items,
            default => [$items],
        };

        $names = [];
        $ids = [];

        foreach ($items as $item) {
            if ($item instanceof Model) {
                $names[] = $item->name;
            } else {
                $ids[] = $item;
            }
        }

        if ($ids !== []) {
            $names = [
                ...$names,
                ...$lookupClass::query()->whereKey($ids)->pluck('name')->all(),
            ];
        }

        return $names;
    }

    /**
     * A Role carries its own tenant_id directly (authoritative); anything
     * else (e.g. a User being granted a role) reads the ambient team id spatie
     * itself used to decide the pivot's team_id at grant time. Either way,
     * HubAccess::TEAM_ID (0) — a sentinel, not a real tenant — normalises to
     * null, same as every other Hub-scoped activity row.
     */
    private function resolveTenantId(Model $target): ?int
    {
        $tenantId = $target->tenant_id ?? app(PermissionRegistrar::class)->getPermissionsTeamId();

        if ($tenantId === null || (int) $tenantId === HubAccess::TEAM_ID) {
            return null;
        }

        return (int) $tenantId;
    }
}
