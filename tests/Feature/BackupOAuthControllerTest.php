<?php

namespace Tests\Feature;

use App\Enums\BackupDestinationProvider;
use App\Models\BackupDestinationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Accounting\OAuthState;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    private static int $tenantCounter = 0;

    private function makeTenantAndUser(string $role = 'director'): array
    {
        $suffix = ++self::$tenantCounter;

        $tenant = Tenant::create(['name' => "Firm {$suffix}", 'slug' => "backup-oauth-firm-{$suffix}", 'reference_prefix' => "BO{$suffix}"]);
        TenantRoleSeeder::seed($tenant);

        $user = User::factory()->create();
        CurrentTenant::set($tenant);
        $user->tenants()->attach($tenant);
        $user->assignRole($role);
        CurrentTenant::clear();

        return [$tenant, $user];
    }

    public function test_a_successful_callback_creates_a_connection_and_redirects_with_success(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'real-token', 'refresh_token' => 'real-refresh', 'expires_in' => 3600,
            ]),
        ]);

        [$tenant, $user] = $this->makeTenantAndUser('director');
        $this->actingAs($user);

        $state = OAuthState::generate($tenant, $user);

        $response = $this->get('/integrations/backups/sharepoint/callback?'.http_build_query([
            'code' => 'auth-code',
            'state' => $state,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $connection = BackupDestinationConnection::forTenantAndProvider($tenant, BackupDestinationProvider::SharePoint);
        $this->assertNotNull($connection);
        $this->assertTrue($connection->isConnected());
        $this->assertSame($user->id, $connection->connected_by);
    }

    public function test_it_rejects_a_state_belonging_to_a_different_user(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser('director');
        [, $otherUser] = $this->makeTenantAndUser('director');
        $this->actingAs($user);

        $state = OAuthState::generate($tenant, $otherUser);

        $this->get('/integrations/backups/sharepoint/callback?'.http_build_query(['code' => 'x', 'state' => $state]))
            ->assertRedirect();

        $this->assertNull(BackupDestinationConnection::forTenantAndProvider($tenant, BackupDestinationProvider::SharePoint));
    }

    public function test_it_rejects_a_user_without_manage_backups(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser('solicitor');
        $this->actingAs($user);

        $state = OAuthState::generate($tenant, $user);

        $this->get('/integrations/backups/sharepoint/callback?'.http_build_query(['code' => 'x', 'state' => $state]))
            ->assertRedirect();

        $this->assertNull(BackupDestinationConnection::forTenantAndProvider($tenant, BackupDestinationProvider::SharePoint));
    }

    public function test_a_successful_google_drive_callback_creates_a_connection(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'real-token', 'refresh_token' => 'real-refresh', 'expires_in' => 3600,
            ]),
        ]);

        [$tenant, $user] = $this->makeTenantAndUser('director');
        $this->actingAs($user);

        $state = OAuthState::generate($tenant, $user);

        $response = $this->get('/integrations/backups/google_drive/callback?'.http_build_query([
            'code' => 'auth-code',
            'state' => $state,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $connection = BackupDestinationConnection::forTenantAndProvider($tenant, BackupDestinationProvider::GoogleDrive);
        $this->assertNotNull($connection);
        $this->assertTrue($connection->isConnected());
    }

    public function test_an_unknown_provider_404s(): void
    {
        [, $user] = $this->makeTenantAndUser('director');
        $this->actingAs($user);

        $this->get('/integrations/backups/dropbox/callback?code=x&state=y')->assertNotFound();
    }
}
