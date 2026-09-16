<?php

namespace Tests\Feature\Filament;

use App\Enums\PrecedentTemplateType;
use App\Filament\Hub\Resources\PrecedentTemplateLibrary\PrecedentTemplateLibraryResource;
use App\Models\PrecedentTemplate;
use App\Models\User;
use App\Support\Hub\HubAccess;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\HubRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Master templates live outside every firm's tenant scope — this covers the
 * two things that make that safe: only Hub staff with the library
 * permission can manage them, and a firm's own PrecedentTemplate resource
 * (ordinary TenantScope) never surfaces a master, even though both query the
 * same table.
 */
class PrecedentTemplateLibraryResourceTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function hubUser(string $role): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        return $user;
    }

    private function makeMaster(): PrecedentTemplate
    {
        CurrentTenant::clear();

        return PrecedentTemplate::create([
            'name' => 'Client Care Letter',
            'template_key' => 'client_care',
            'type' => PrecedentTemplateType::RichText,
            'content' => '<p>Dear {{client_name}}</p>',
            'available_fields' => [],
            'is_master' => true,
        ]);
    }

    public function test_only_hub_staff_with_the_library_permission_can_access_it(): void
    {
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));
        HubAccess::withHubTeam(fn () => $this->assertTrue(PrecedentTemplateLibraryResource::canAccess()));

        $noPermissionUser = User::factory()->create();
        $this->actingAs($noPermissionUser);
        $this->assertFalse(PrecedentTemplateLibraryResource::canAccess());
    }

    public function test_creating_a_master_leaves_it_without_a_tenant(): void
    {
        $master = $this->makeMaster();

        $this->assertNull($master->tenant_id);
        $this->assertTrue($master->is_master);
    }

    public function test_a_firms_own_precedent_template_resource_never_sees_a_master(): void
    {
        $this->makeMaster();

        $tenant = $this->setUpTenant();
        CurrentTenant::set($tenant);
        PrecedentTemplate::create([
            'name' => 'My Own Letter',
            'template_key' => 'my_own_letter',
            'type' => PrecedentTemplateType::RichText,
            'content' => '<p>Hello</p>',
            'available_fields' => [],
        ]);

        $tenantVisible = PrecedentTemplate::query()->pluck('name')->all();

        $this->assertSame(['My Own Letter'], $tenantVisible);
    }

    public function test_the_library_resource_query_only_ever_returns_masters(): void
    {
        $this->makeMaster();

        $tenant = $this->setUpTenant();
        CurrentTenant::set($tenant);
        PrecedentTemplate::create([
            'name' => 'My Own Letter',
            'template_key' => 'my_own_letter',
            'type' => PrecedentTemplateType::RichText,
            'content' => '<p>Hello</p>',
            'available_fields' => [],
        ]);

        $libraryVisible = PrecedentTemplateLibraryResource::getEloquentQuery()->pluck('name')->all();

        $this->assertSame(['Client Care Letter'], $libraryVisible);
    }
}
