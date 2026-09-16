<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real HTTP page loads rather than Livewire::test() — see the Feature B
 * PrecedentTemplatesPageSmokeTest precedent in this same codebase for why:
 * Livewire::test() proved unreliable for reasons unrelated to the feature
 * under test in this environment, while a real request reproduces exactly
 * what a browser does.
 *
 * The Client page's "Request Backup" button lives inside a relation manager
 * tab, which Filament only renders once that tab is active — a bare GET
 * only ever sees the tab *label*, not its lazily-loaded content, so that
 * half is instead checked on the Matter page, where the same action is a
 * page-level header button rendered unconditionally.
 */
class ClientBackupsPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    private function actingUser(string $role): User
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

        return $user;
    }

    public function test_a_director_sees_the_backups_tab_on_the_client_page(): void
    {
        $this->actingUser('director');

        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555123456', 'source' => ClientSource::Phone,
        ]);

        $this->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertOk()
            ->assertSee('Backups');
    }

    public function test_a_solicitor_without_manage_backups_does_not_see_the_backups_tab(): void
    {
        $this->actingUser('solicitor');

        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555123456', 'source' => ClientSource::Phone,
        ]);

        $this->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertOk()
            ->assertDontSee('Backups');
    }

    public function test_a_director_sees_the_request_backup_button_on_the_matter_page(): void
    {
        $this->actingUser('director');

        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555123456', 'source' => ClientSource::Phone,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);

        $this->get(MatterResource::getUrl('view', ['record' => $matter]))
            ->assertOk()
            ->assertSee('Request Backup');
    }

    public function test_a_solicitor_without_manage_backups_does_not_see_the_request_backup_button_on_the_matter_page(): void
    {
        $this->actingUser('solicitor');

        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555123456', 'source' => ClientSource::Phone,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);

        $this->get(MatterResource::getUrl('view', ['record' => $matter]))
            ->assertOk()
            ->assertDontSee('Request Backup');
    }
}
