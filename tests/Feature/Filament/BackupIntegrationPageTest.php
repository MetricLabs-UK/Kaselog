<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\Integrations\BackupIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\IntegrationCatalog;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupIntegrationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    private function actingUser(string $role): array
    {
        $tenant = Tenant::firstOrCreate(['slug' => 'lostock-legal'], ['name' => 'Lostock Legal', 'reference_prefix' => 'LL']);
        TenantRoleSeeder::seed($tenant);

        $user = User::factory()->create();
        CurrentTenant::set($tenant);
        $user->tenants()->attach($tenant);
        $user->assignRole($role);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);

        return [$user, $tenant];
    }

    public function test_a_director_can_access_the_backup_integration_page_and_sees_the_backup_all_matters_action(): void
    {
        $this->actingUser('director');

        $this->get(BackupIntegration::getUrl())
            ->assertOk()
            ->assertSee('Backup All Matters');
    }

    public function test_a_solicitor_without_manage_backups_cannot_access_the_backup_integration_page(): void
    {
        $this->actingUser('solicitor');

        $this->get(BackupIntegration::getUrl())->assertForbidden();
    }

    public function test_the_integration_catalog_only_returns_entries_the_user_can_manage(): void
    {
        [, $tenant] = $this->actingUser('director');
        $this->assertCount(2, IntegrationCatalog::entriesFor($tenant));

        [, $tenant] = $this->actingUser('solicitor');
        $this->assertCount(0, IntegrationCatalog::entriesFor($tenant));
    }
}
