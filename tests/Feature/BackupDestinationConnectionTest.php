<?php

namespace Tests\Feature;

use App\Enums\BackupDestinationProvider;
use App\Models\BackupDestinationConnection;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class BackupDestinationConnectionTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_a_tenant_can_hold_both_a_sharepoint_and_a_google_drive_connection_at_once(): void
    {
        $this->setUpTenant();

        BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'sp-token',
            'connected_at' => now(),
        ]);

        BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::GoogleDrive,
            'access_token' => 'gd-token',
            'connected_at' => now(),
        ]);

        $this->assertSame(2, BackupDestinationConnection::allTenants()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_for_tenant_and_provider_finds_the_right_connection_regardless_of_ambient_tenant(): void
    {
        $this->setUpTenant();
        BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'sp-token',
            'connected_at' => now(),
        ]);

        $otherTenant = Tenant::create(['name' => 'Other Firm', 'slug' => 'other-backup-dest-firm', 'reference_prefix' => 'OBF']);
        CurrentTenant::set($otherTenant);

        $found = BackupDestinationConnection::forTenantAndProvider($this->tenant, BackupDestinationProvider::SharePoint);

        $this->assertNotNull($found);
        $this->assertSame($this->tenant->id, $found->tenant_id);

        $this->assertNull(BackupDestinationConnection::forTenantAndProvider($otherTenant, BackupDestinationProvider::SharePoint));
    }

    public function test_is_connected_is_false_once_disconnected(): void
    {
        $this->setUpTenant();
        $connection = BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'sp-token',
            'refresh_token' => 'sp-refresh',
            'connected_at' => now(),
        ]);

        $this->assertTrue($connection->isConnected());

        $connection->disconnect();

        $this->assertFalse($connection->fresh()->isConnected());
        $this->assertNull($connection->fresh()->access_token);
        $this->assertNull($connection->fresh()->refresh_token);
        $this->assertNotNull($connection->fresh()->disconnected_at);
    }

    public function test_has_site_selected_requires_both_a_live_connection_and_a_drive_id(): void
    {
        $this->setUpTenant();

        $connectedNoSite = BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'sp-token',
            'connected_at' => now(),
        ]);
        $this->assertTrue($connectedNoSite->isConnected());
        $this->assertFalse($connectedNoSite->hasSiteSelected());

        $connectedNoSite->forceFill(['site_id' => 'site-1', 'site_name' => 'Firm Documents', 'drive_id' => 'drive-1'])->save();
        $this->assertTrue($connectedNoSite->fresh()->hasSiteSelected());
    }

    public function test_disconnect_clears_the_selected_site_too(): void
    {
        $this->setUpTenant();
        $connection = BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'sp-token',
            'site_id' => 'site-1',
            'site_name' => 'Firm Documents',
            'drive_id' => 'drive-1',
            'connected_at' => now(),
        ]);

        $connection->disconnect();

        $fresh = $connection->fresh();
        $this->assertFalse($fresh->hasSiteSelected());
        $this->assertNull($fresh->site_id);
        $this->assertNull($fresh->site_name);
        $this->assertNull($fresh->drive_id);
    }

    public function test_token_needs_refresh_within_five_minutes_of_expiry(): void
    {
        $this->setUpTenant();

        $stale = BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'sp-token',
            'token_expires_at' => now()->addMinutes(2),
        ]);
        $this->assertTrue($stale->tokenNeedsRefresh());

        $fresh = BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::GoogleDrive,
            'access_token' => 'gd-token',
            'token_expires_at' => now()->addHour(),
        ]);
        $this->assertFalse($fresh->tokenNeedsRefresh());
    }
}
