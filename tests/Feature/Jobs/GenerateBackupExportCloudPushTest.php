<?php

namespace Tests\Feature\Jobs;

use App\Enums\BackupDestinationProvider;
use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Enums\BackupExportStatus;
use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Jobs\GenerateBackupExport;
use App\Models\BackupDestinationConnection;
use App\Models\BackupExport;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\User;
use App\Notifications\BackupExportFailedNotification;
use App\Notifications\BackupExportReadyNotification;
use App\Support\Backups\BackupDestinationProviderContract;
use App\Support\Backups\SharePoint\SharePointBackupProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Phase 5 — pushing a single-client export straight to a connected cloud
 * destination instead of leaving it for direct download. The actual Graph
 * upload call (SharePointBackupProvider::uploadFile()) can't be exercised
 * here — same untestable-without-real-credentials boundary as everywhere
 * else this provider is tested — so it's swapped for a mock bound in the
 * container, verifying GenerateBackupExport's own orchestration: it calls
 * uploadFile() with the right connection and a sensible path, deletes the
 * local copy afterward, and records the export as completed with no local
 * file to download.
 */
class GenerateBackupExportCloudPushTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private Client $client;

    private BackupDestinationConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('exports');
        Notification::fake();

        $this->setUpTenant();

        $this->client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555123456', 'source' => ClientSource::Phone,
        ]);

        $matter = Matter::create(['client_id' => $this->client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);
        Storage::disk('documents')->put("matter-docs/{$matter->id}/statement.pdf", 'contents');
        MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => User::factory()->create()->id,
            'filename' => 'statement.pdf', 'path' => "matter-docs/{$matter->id}/statement.pdf",
        ]);

        $this->connection = BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => 'token',
            'site_id' => 'site-1',
            'site_name' => 'Firm Documents',
            'drive_id' => 'drive-1',
            'connected_at' => now(),
        ]);
    }

    private function makeExport(): BackupExport
    {
        return BackupExport::create([
            'scope' => BackupExportScope::SingleClient,
            'client_id' => $this->client->id,
            'destination' => BackupExportDestination::SharePoint,
            'status' => BackupExportStatus::Pending,
        ]);
    }

    public function test_a_cloud_destination_export_uploads_the_zip_and_keeps_no_local_copy(): void
    {
        $fakeProvider = Mockery::mock(BackupDestinationProviderContract::class);
        $fakeProvider->shouldReceive('uploadFile')
            ->once()
            ->withArgs(function ($connection, string $path, string $content) {
                return $connection->is($this->connection)
                    && str_contains($path, 'jane-doe')
                    && str_ends_with($path, '.zip')
                    && str_starts_with($content, 'PK'); // real zip magic bytes
            });
        $this->app->instance(SharePointBackupProvider::class, $fakeProvider);

        $export = $this->makeExport();

        (new GenerateBackupExport($export->id))->handle();

        $fresh = $export->fresh();
        $this->assertSame(BackupExportStatus::Completed, $fresh->status);
        $this->assertNull($fresh->file_path);
        $this->assertNull($fresh->expires_at);
        $this->assertNotNull($fresh->file_size);
        $this->assertGreaterThan(0, $fresh->file_size);

        // Nothing left behind on the exports disk for a cloud-pushed export.
        Storage::disk('exports')->assertDirectoryEmpty((string) $this->tenant->id);
    }

    public function test_it_notifies_the_requester_after_a_successful_cloud_push(): void
    {
        $fakeProvider = Mockery::mock(BackupDestinationProviderContract::class);
        $fakeProvider->shouldReceive('uploadFile')->once();
        $this->app->instance(SharePointBackupProvider::class, $fakeProvider);

        $requester = User::factory()->create();
        $export = $this->makeExport();
        $export->update(['requested_by_user_id' => $requester->id]);

        (new GenerateBackupExport($export->id))->handle();

        Notification::assertSentTo($requester, BackupExportReadyNotification::class);
    }

    public function test_a_disconnected_destination_fails_the_export_instead_of_uploading_anywhere(): void
    {
        $this->connection->disconnect();
        $requester = User::factory()->create();
        $export = $this->makeExport();
        $export->update(['requested_by_user_id' => $requester->id]);

        (new GenerateBackupExport($export->id))->handle();

        $fresh = $export->fresh();
        $this->assertSame(BackupExportStatus::Failed, $fresh->status);
        $this->assertNull($fresh->file_path);

        Notification::assertSentTo($requester, BackupExportFailedNotification::class);
    }

    public function test_a_failed_upload_still_cleans_up_the_local_temp_file(): void
    {
        $fakeProvider = Mockery::mock(BackupDestinationProviderContract::class);
        $fakeProvider->shouldReceive('uploadFile')->once()->andThrow(new \RuntimeException('Graph is unavailable'));
        $this->app->instance(SharePointBackupProvider::class, $fakeProvider);

        $export = $this->makeExport();

        (new GenerateBackupExport($export->id))->handle();

        $this->assertSame(BackupExportStatus::Failed, $export->fresh()->status);
        Storage::disk('exports')->assertDirectoryEmpty((string) $this->tenant->id);
    }

    /**
     * Phase 6's daily push is the only thing that ever combines WholeFirm
     * scope with a cloud destination (a human choosing "backup all matters"
     * is always Download-only). Regression coverage for a real bug found
     * while building Phase 6: the remote path used to unconditionally
     * resolve a client name, which threw for a whole-firm export (no
     * client_id at all).
     */
    public function test_a_whole_firm_export_can_be_pushed_to_a_cloud_destination_too(): void
    {
        $fakeProvider = Mockery::mock(BackupDestinationProviderContract::class);
        $fakeProvider->shouldReceive('uploadFile')
            ->once()
            ->withArgs(fn ($connection, string $path, string $content) => $connection->is($this->connection)
                && str_contains($path, 'whole-firm')
                && str_ends_with($path, '.zip'));
        $this->app->instance(SharePointBackupProvider::class, $fakeProvider);

        $export = BackupExport::create([
            'scope' => BackupExportScope::WholeFirm,
            'client_id' => null,
            'destination' => BackupExportDestination::SharePoint,
            'status' => BackupExportStatus::Pending,
        ]);

        (new GenerateBackupExport($export->id))->handle();

        $this->assertSame(BackupExportStatus::Completed, $export->fresh()->status);
    }
}
