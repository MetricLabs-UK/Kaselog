<?php

namespace Tests\Feature\Filament;

use App\Enums\BackupDestinationProvider;
use App\Filament\Admin\Pages\Integrations\BackupIntegration;
use App\Models\BackupDestinationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both SharePoint (Phase 3) and Google Drive (Phase 4) are real,
 * available providers as of this test — neither shows a "coming soon"
 * placeholder any more (see BackupDestinationProviderRegistry::MAP).
 */
class BackupIntegrationConnectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    private function actingDirector(): Tenant
    {
        $tenant = Tenant::firstOrCreate(['slug' => 'lostock-legal'], ['name' => 'Lostock Legal', 'reference_prefix' => 'LL']);
        TenantRoleSeeder::seed($tenant);

        $user = User::factory()->create();
        CurrentTenant::set($tenant);
        $user->tenants()->attach($tenant);
        $user->assignRole('director');

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);

        return $tenant;
    }

    public function test_the_page_shows_both_providers_as_connectable_when_neither_is_connected(): void
    {
        $this->actingDirector();

        $this->get(BackupIntegration::getUrl())
            ->assertOk()
            ->assertSee('SharePoint')
            ->assertSee('Google Drive')
            ->assertSeeInOrder(['SharePoint', 'Not connected', 'Google Drive', 'Not connected'])
            ->assertDontSee('Coming soon');
    }

    public function test_a_sharepoint_connection_with_no_site_yet_prompts_to_choose_one(): void
    {
        $tenant = $this->actingDirector();

        BackupDestinationConnection::create([
            'tenant_id' => $tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'token',
            'connected_at' => now(),
        ]);

        $this->get(BackupIntegration::getUrl())
            ->assertOk()
            ->assertSee('choose a shared location')
            ->assertSee("Search for your firm's SharePoint site", false);
    }

    public function test_a_google_drive_connection_with_no_shared_drive_yet_prompts_to_choose_one(): void
    {
        $tenant = $this->actingDirector();

        BackupDestinationConnection::create([
            'tenant_id' => $tenant->id,
            'provider' => BackupDestinationProvider::GoogleDrive,
            'access_token' => 'token',
            'connected_at' => now(),
        ]);

        $this->get(BackupIntegration::getUrl())
            ->assertOk()
            ->assertSee('choose a shared location')
            ->assertSee("Search for your firm's Google Drive shared drive", false);
    }

    public function test_a_connection_with_a_site_selected_shows_the_site_name_not_the_search_box(): void
    {
        $tenant = $this->actingDirector();

        BackupDestinationConnection::create([
            'tenant_id' => $tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'token',
            'site_id' => 'site-1',
            'site_name' => 'Firm Documents',
            'drive_id' => 'drive-1',
            'connected_at' => now(),
        ]);

        $this->get(BackupIntegration::getUrl())
            ->assertOk()
            ->assertSee('Connected to "Firm Documents"', false)
            ->assertDontSee('Search for your firm');
    }
}
