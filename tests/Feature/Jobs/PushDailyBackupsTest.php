<?php

namespace Tests\Feature\Jobs;

use App\Enums\BackupDestinationProvider;
use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Jobs\GenerateBackupExport;
use App\Jobs\PushDailyBackups;
use App\Models\BackupDestinationConnection;
use App\Models\BackupExport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Phase 6 — this job's own responsibility is deciding which connections are
 * actually ready and queueing the right BackupExport + GenerateBackupExport
 * pair for each; GenerateBackupExport's own bundling/upload behaviour is
 * already covered elsewhere, so it's faked here rather than re-verified.
 */
class PushDailyBackupsTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    public function test_it_queues_a_whole_firm_export_for_each_ready_connection(): void
    {
        Queue::fake();
        $this->setUpTenant();
        $connector = User::factory()->create();

        $connection = BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'token',
            'site_id' => 'site-1',
            'site_name' => 'Firm Documents',
            'drive_id' => 'drive-1',
            'connected_by' => $connector->id,
            'connected_at' => now(),
        ]);

        (new PushDailyBackups)->handle();

        $export = BackupExport::allTenants()->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($export);
        $this->assertSame(BackupExportScope::WholeFirm, $export->scope);
        $this->assertSame(BackupExportDestination::SharePoint, $export->destination);
        $this->assertNull($export->client_id);
        $this->assertSame($connector->id, $export->requested_by_user_id);

        Queue::assertPushed(GenerateBackupExport::class, fn (GenerateBackupExport $job) => $job->backupExportId === $export->id);
    }

    public function test_it_skips_a_connection_that_has_not_selected_a_site_yet(): void
    {
        Queue::fake();
        $this->setUpTenant();

        BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'token',
            'connected_at' => now(),
            // No site_id/drive_id — still mid-setup.
        ]);

        (new PushDailyBackups)->handle();

        $this->assertSame(0, BackupExport::allTenants()->where('tenant_id', $this->tenant->id)->count());
        Queue::assertNotPushed(GenerateBackupExport::class);
    }

    public function test_a_firm_with_both_destinations_ready_gets_one_export_per_destination(): void
    {
        Queue::fake();
        $this->setUpTenant();

        BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'sp-token',
            'site_id' => 'site-1', 'site_name' => 'Firm Documents', 'drive_id' => 'drive-1',
            'connected_at' => now(),
        ]);
        BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::GoogleDrive,
            'access_token' => 'gd-token',
            'site_id' => 'shared-drive-1', 'site_name' => 'Firm Shared Drive', 'drive_id' => 'shared-drive-1',
            'connected_at' => now(),
        ]);

        (new PushDailyBackups)->handle();

        $destinations = BackupExport::allTenants()->where('tenant_id', $this->tenant->id)->pluck('destination')->map(fn ($d) => $d->value)->sort()->values()->all();
        $this->assertSame(['google_drive', 'sharepoint'], $destinations);
        Queue::assertPushed(GenerateBackupExport::class, 2);
    }

    public function test_it_covers_every_tenant_with_a_ready_connection_and_restores_the_ambient_tenant(): void
    {
        Queue::fake();
        $tenantA = $this->setUpTenant();
        BackupDestinationConnection::create([
            'tenant_id' => $tenantA->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'token', 'site_id' => 's1', 'site_name' => 'A Docs', 'drive_id' => 'd1',
            'connected_at' => now(),
        ]);

        $tenantB = Tenant::create(['name' => 'Other Firm', 'slug' => 'daily-backup-firm-b', 'reference_prefix' => 'DBB']);
        CurrentTenant::set($tenantB);
        BackupDestinationConnection::create([
            'tenant_id' => $tenantB->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'token', 'site_id' => 's2', 'site_name' => 'B Docs', 'drive_id' => 'd2',
            'connected_at' => now(),
        ]);

        CurrentTenant::set($tenantA);

        (new PushDailyBackups)->handle();

        $this->assertSame(1, BackupExport::allTenants()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(1, BackupExport::allTenants()->where('tenant_id', $tenantB->id)->count());
        Queue::assertPushed(GenerateBackupExport::class, 2);

        // The job must not leak a different tenant into the ambient context
        // it was called under.
        $this->assertSame($tenantA->id, CurrentTenant::get()?->id);
    }

    public function test_a_disconnected_connection_is_never_pushed_to(): void
    {
        Queue::fake();
        $this->setUpTenant();

        $connection = BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'token', 'site_id' => 's1', 'site_name' => 'Firm Documents', 'drive_id' => 'd1',
            'connected_at' => now(),
        ]);
        $connection->disconnect();

        (new PushDailyBackups)->handle();

        $this->assertSame(0, BackupExport::allTenants()->where('tenant_id', $this->tenant->id)->count());
        Queue::assertNotPushed(GenerateBackupExport::class);
    }
}
