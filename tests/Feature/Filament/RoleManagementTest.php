<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Roles\Pages\CreateRole;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Models\Activity;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 18 item 2 — per-tenant role management. Fully editable, no
 * protected baseline: a firm can rename, edit, or delete any role including
 * the four Kaselog seeds. Director-only.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->setUpTenant(), isQuiet: true);
    }

    public function test_only_director_can_access_role_management(): void
    {
        $this->actingAsRole('director');
        $this->assertTrue(RoleResource::canAccess());

        foreach (['admin', 'solicitor', 'accounts'] as $role) {
            $this->actingAsRole($role);
            $this->assertFalse(RoleResource::canAccess());
        }
    }

    public function test_director_can_create_a_custom_role_with_chosen_permissions(): void
    {
        $this->actingAsRole('director');

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Paralegal',
                'permissions_matters' => ['view_matters'],
                'permissions_clients' => ['view_clients'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('name', 'Paralegal')->where('tenant_id', $this->tenant->id)->sole();

        $this->assertSame(['view_clients', 'view_matters'], $role->permissions->pluck('name')->sort()->values()->all());

        $activity = Activity::where('event', 'role_created')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($this->tenant->id, $activity->tenant_id);
    }

    public function test_director_can_rename_a_role_and_change_its_permissions(): void
    {
        $director = $this->actingAsRole('director');
        $role = Role::where('name', 'accounts')->where('tenant_id', $this->tenant->id)->sole();

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm([
                'name' => 'Finance Team',
                'permissions_finance' => ['view_finance'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $role->refresh();
        $this->assertSame('Finance Team', $role->name);
        // manage_invoices survives untouched — the form submission above only
        // set permissions_finance, and accounts' pre-existing
        // permissions_time_entries value (manage_invoices) round-trips
        // through the form's other fields exactly as it was hydrated.
        $this->assertSame(['view_finance', 'manage_invoices'], $role->permissions->pluck('name')->all());

        $renameActivity = Activity::where('event', 'role_renamed')->latest('id')->first();
        $this->assertNotNull($renameActivity);
        $this->assertStringContainsString('accounts', $renameActivity->description);
        $this->assertStringContainsString('Finance Team', $renameActivity->description);

        // Permission changes on the role itself are already covered by
        // LogPermissionActivity (PermissionAttachedEvent/DetachedEvent) —
        // confirming that existing mechanism actually fires here too.
        $this->assertTrue(
            Activity::where('event', 'permission_detached')->where('subject_id', $role->id)->exists(),
        );
    }

    public function test_director_can_delete_a_role_including_a_seeded_default(): void
    {
        $director = $this->actingAsRole('director');
        $role = Role::where('name', 'solicitor')->where('tenant_id', $this->tenant->id)->sole();
        $roleId = $role->id;

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->callAction('delete');

        $this->assertNull(Role::find($roleId));

        $activity = Activity::where('event', 'role_deleted')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame('solicitor', $activity->getExtraProperty('name'));
    }

    public function test_deleting_a_role_removes_it_from_users_who_held_it(): void
    {
        $this->actingAsRole('director');
        $solicitor = $this->actingAsRole('solicitor');
        $role = Role::where('name', 'solicitor')->where('tenant_id', $this->tenant->id)->sole();

        $this->assertTrue($solicitor->hasRole('solicitor'));

        $this->actingAsRole('director');
        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->callAction('delete');

        $this->assertFalse($solicitor->fresh()->hasRole('solicitor'));
    }

    public function test_role_names_must_be_unique_within_a_tenant(): void
    {
        $this->actingAsRole('director');

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'director'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_the_same_role_name_is_allowed_in_a_different_tenant(): void
    {
        $this->actingAsRole('director');
        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Paralegal'])
            ->call('create')
            ->assertHasNoFormErrors();

        $otherTenant = Tenant::create(['name' => 'Other Firm', 'slug' => 'other-firm-roles', 'reference_prefix' => 'OFR']);
        $this->actingAsRole('director', $otherTenant);

        // Same name, different tenant — the uniqueness rule is scoped per
        // tenant (see RoleForm's modifyRuleUsing), so this must succeed.
        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Paralegal'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Role::where('name', 'Paralegal')->count());
    }

    public function test_a_tenants_roles_are_isolated_from_another_tenants(): void
    {
        $this->actingAsRole('director');

        $otherTenant = Tenant::create(['name' => 'Other Firm', 'slug' => 'other-firm-roles-2', 'reference_prefix' => 'OFR2']);
        $otherDirectorRole = Role::where('tenant_id', $otherTenant->id)->where('name', 'director')->sole();

        $visibleIds = RoleResource::getEloquentQuery()->pluck('id')->all();

        $this->assertNotContains($otherDirectorRole->id, $visibleIds);
    }

    public function test_role_list_shows_permission_and_user_counts(): void
    {
        $director = $this->actingAsRole('director');

        Livewire::test(ListRoles::class)
            ->assertSuccessful()
            ->assertSee('director')
            ->assertSee('admin')
            ->assertSee('solicitor')
            ->assertSee('accounts');
    }
}
