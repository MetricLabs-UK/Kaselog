<?php

namespace Tests\Feature\Jobs;

use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Enums\BackupExportStatus;
use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Jobs\GenerateBackupExport;
use App\Models\BackupExport;
use App\Models\Client;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BackupExportFailedNotification;
use App\Notifications\BackupExportReadyNotification;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;
use ZipArchive;

class GenerateBackupExportTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('exports');
        Notification::fake();

        $this->setUpTenant();

        $this->client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555123456', 'address' => '1 High Street, Leeds', 'source' => ClientSource::Phone,
        ]);
    }

    private function makeMatterWithDocuments(string $referenceSuffix): Matter
    {
        $uploader = User::factory()->create();

        $matter = Matter::create([
            'client_id' => $this->client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active,
            'notes' => "Notes for matter {$referenceSuffix}.",
        ]);

        Storage::disk('documents')->put("matter-docs/{$matter->id}/statement.pdf", 'fake pdf contents');
        MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => $uploader->id,
            'filename' => 'statement.pdf', 'path' => "matter-docs/{$matter->id}/statement.pdf",
        ]);

        Storage::disk('documents')->put("matter-docs/{$matter->id}/letter.docx", 'fake generated contents');
        GeneratedDocument::create([
            'matter_id' => $matter->id, 'precedent_template_id' => null, 'generated_by_user_id' => $uploader->id,
            'filename' => 'letter.docx', 'file_path' => "matter-docs/{$matter->id}/letter.docx", 'generated_at' => now(),
        ]);

        return $matter;
    }

    public function test_a_single_client_export_bundles_every_matters_documents_and_a_data_csv(): void
    {
        $matterA = $this->makeMatterWithDocuments('A');
        $matterB = $this->makeMatterWithDocuments('B');

        $export = BackupExport::create([
            'requested_by_user_id' => null,
            'scope' => BackupExportScope::SingleClient,
            'client_id' => $this->client->id,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Pending,
        ]);

        (new GenerateBackupExport($export->id))->handle();

        $fresh = $export->fresh();
        $this->assertSame(BackupExportStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->file_path);
        $this->assertNotNull($fresh->file_size);
        $this->assertNotNull($fresh->expires_at);
        Storage::disk('exports')->assertExists($fresh->file_path);

        $absolutePath = Storage::disk('exports')->path($fresh->file_path);
        $zip = new ZipArchive;
        $zip->open($absolutePath);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('matters.csv', $names);
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_contains($n, Str::slug($matterA->reference)) && str_ends_with($n, 'statement.pdf')));
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_contains($n, Str::slug($matterB->reference)) && str_ends_with($n, 'letter.docx')));

        $csv = $zip->getFromName('matters.csv');
        $this->assertStringContainsString('Jane Doe', $csv);
        $this->assertStringContainsString($matterA->reference, $csv);
        $this->assertStringContainsString($matterB->reference, $csv);
        $this->assertStringContainsString('Notes for matter A.', $csv);

        $zip->close();
    }

    public function test_it_never_includes_another_clients_documents(): void
    {
        $matterA = $this->makeMatterWithDocuments('Mine');

        $otherClient = Client::create([
            'first_name' => 'Other', 'last_name' => 'Person', 'email' => 'other@example.com',
            'phone' => '07000000000', 'source' => ClientSource::Phone,
        ]);
        $otherMatter = Matter::create(['client_id' => $otherClient->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);
        Storage::disk('documents')->put("matter-docs/{$otherMatter->id}/secret.pdf", 'someone elses file');
        MatterDocument::create([
            'matter_id' => $otherMatter->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => User::factory()->create()->id,
            'filename' => 'secret.pdf', 'path' => "matter-docs/{$otherMatter->id}/secret.pdf",
        ]);

        $export = BackupExport::create([
            'scope' => BackupExportScope::SingleClient,
            'client_id' => $this->client->id,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Pending,
        ]);

        (new GenerateBackupExport($export->id))->handle();

        $absolutePath = Storage::disk('exports')->path($export->fresh()->file_path);
        $zip = new ZipArchive;
        $zip->open($absolutePath);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $csv = $zip->getFromName('matters.csv') ?: '';
        $zip->close();

        $this->assertFalse(collect($names)->contains(fn (string $n) => str_ends_with($n, 'secret.pdf')));
        $this->assertStringNotContainsString('Other Person', $csv);
    }

    public function test_it_notifies_the_requester_when_ready(): void
    {
        $this->makeMatterWithDocuments('A');
        $requester = User::factory()->create();

        $export = BackupExport::create([
            'requested_by_user_id' => $requester->id,
            'scope' => BackupExportScope::SingleClient,
            'client_id' => $this->client->id,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Pending,
        ]);

        (new GenerateBackupExport($export->id))->handle();

        Notification::assertSentTo($requester, BackupExportReadyNotification::class);
    }

    public function test_a_failure_marks_the_export_failed_and_notifies_the_requester(): void
    {
        $requester = User::factory()->create();

        // A single-client export with no client_id is the simplest way to
        // force resolveMatters() to throw, without mocking internals.
        $export = BackupExport::create([
            'requested_by_user_id' => $requester->id,
            'scope' => BackupExportScope::SingleClient,
            'client_id' => null,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Pending,
        ]);

        (new GenerateBackupExport($export->id))->handle();

        $fresh = $export->fresh();
        $this->assertSame(BackupExportStatus::Failed, $fresh->status);
        $this->assertNotNull($fresh->failed_reason);
        $this->assertNull($fresh->file_path);

        Notification::assertSentTo($requester, BackupExportFailedNotification::class);
    }

    public function test_two_tenants_exports_stay_isolated(): void
    {
        $this->makeMatterWithDocuments('Mine');

        $tenantB = Tenant::create(['name' => 'Other Firm', 'slug' => 'other-firm-backup', 'reference_prefix' => 'OFB']);
        CurrentTenant::set($tenantB);
        $clientB = Client::create([
            'first_name' => 'Firm', 'last_name' => 'B', 'email' => 'firmb@example.com',
            'phone' => '07111111111', 'source' => ClientSource::Phone,
        ]);
        $matterB = Matter::create(['client_id' => $clientB->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);
        Storage::disk('documents')->put("matter-docs/{$matterB->id}/b.pdf", 'firm b file');
        MatterDocument::create([
            'matter_id' => $matterB->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => User::factory()->create()->id,
            'filename' => 'b.pdf', 'path' => "matter-docs/{$matterB->id}/b.pdf",
        ]);

        $exportB = BackupExport::create([
            'scope' => BackupExportScope::SingleClient,
            'client_id' => $clientB->id,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Pending,
        ]);

        (new GenerateBackupExport($exportB->id))->handle();

        $this->assertSame($tenantB->id, $exportB->fresh()->tenant_id);

        $absolutePath = Storage::disk('exports')->path($exportB->fresh()->file_path);
        $zip = new ZipArchive;
        $zip->open($absolutePath);
        $csv = $zip->getFromName('matters.csv');
        $zip->close();

        $this->assertStringContainsString('Firm B', $csv);
        $this->assertStringNotContainsString('Jane Doe', $csv);
    }
}
