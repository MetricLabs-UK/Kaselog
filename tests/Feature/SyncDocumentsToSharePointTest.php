<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Models\Client;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\PrecedentTemplate;
use App\Notifications\DocumentSyncFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Storage::fake('sharepoint')/'documents' — never the real
 * GWSN\FlysystemSharepoint\SharepointConnector, which makes a genuine
 * Microsoft Graph API call the moment it's constructed. Storage::fake()
 * swaps the disk regardless of its configured driver, so this is safe with
 * blank/no Azure credentials, same as the rest of the suite avoids real
 * external services.
 */
class SyncDocumentsToSharePointTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('sharepoint');
    }

    private function matterDocument(?string $backedUpAt = null): MatterDocument
    {
        $this->setUpTenant();

        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '0700', 'source' => ClientSource::Phone,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => 'active']);

        $path = 'matter-docs/'.$matter->id.'/contract.pdf';
        Storage::disk('documents')->put($path, 'pdf contents');

        $document = MatterDocument::create([
            'matter_id' => $matter->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $client->id,
            'filename' => 'contract.pdf',
            'path' => $path,
        ]);

        // backed_up_at is deliberately not mass-assignable (only the sync
        // command itself should set it) — forceFill() to seed the fixture.
        if ($backedUpAt !== null) {
            $document->forceFill(['backed_up_at' => $backedUpAt])->save();
        }

        return $document;
    }

    public function test_a_never_synced_document_gets_uploaded_and_marked_backed_up(): void
    {
        $document = $this->matterDocument(backedUpAt: null);

        $this->artisan('backup:sync-documents')->assertSuccessful();

        Storage::disk('sharepoint')->assertExists($document->path);
        $this->assertSame('pdf contents', Storage::disk('sharepoint')->get($document->path));
        $this->assertNotNull($document->fresh()->backed_up_at);
    }

    public function test_an_already_synced_unchanged_document_is_not_re_uploaded(): void
    {
        $document = $this->matterDocument(backedUpAt: now()->addMinute()->toDateTimeString());

        $this->artisan('backup:sync-documents')->assertSuccessful();

        Storage::disk('sharepoint')->assertMissing($document->path);
    }

    public function test_a_document_changed_since_its_last_sync_is_re_uploaded(): void
    {
        $document = $this->matterDocument(backedUpAt: now()->subDay()->toDateTimeString());
        $document->touch(); // updated_at now after backed_up_at

        $this->artisan('backup:sync-documents')->assertSuccessful();

        Storage::disk('sharepoint')->assertExists($document->path);
    }

    public function test_a_missing_source_file_fails_that_record_without_aborting_the_rest_and_notifies(): void
    {
        Notification::fake();

        $this->setUpTenant();
        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '0700', 'source' => ClientSource::Phone,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => 'active']);

        // One record whose file genuinely exists...
        $goodPath = 'matter-docs/'.$matter->id.'/good.pdf';
        Storage::disk('documents')->put($goodPath, 'good contents');
        $good = MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => Client::class, 'uploaded_by_id' => $client->id,
            'filename' => 'good.pdf', 'path' => $goodPath,
        ]);

        // ...and one whose file is missing from the source disk entirely.
        $missing = MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => Client::class, 'uploaded_by_id' => $client->id,
            'filename' => 'missing.pdf', 'path' => 'matter-docs/'.$matter->id.'/missing.pdf',
        ]);

        $this->artisan('backup:sync-documents')->assertFailed();

        Storage::disk('sharepoint')->assertExists($goodPath);
        $this->assertNotNull($good->fresh()->backed_up_at);
        $this->assertNull($missing->fresh()->backed_up_at);

        Notification::assertSentTo(
            new \Spatie\Backup\Notifications\Notifiable,
            DocumentSyncFailedNotification::class,
            fn (DocumentSyncFailedNotification $notification): bool => $notification->failedCount === 1 && $notification->syncedCount === 1,
        );
    }

    public function test_generated_documents_and_precedent_templates_are_also_synced(): void
    {
        $this->setUpTenant();
        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '0700', 'source' => ClientSource::Phone,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => 'active']);

        $genPath = 'generated-documents/letter.docx';
        Storage::disk('documents')->put($genPath, 'generated contents');
        $generated = GeneratedDocument::create([
            'matter_id' => $matter->id,
            'filename' => 'letter.docx',
            'file_path' => $genPath,
            'generated_at' => now(),
        ]);

        $templatePath = 'precedent-templates/template.docx';
        Storage::disk('documents')->put($templatePath, 'template contents');
        $template = PrecedentTemplate::create([
            'name' => 'Letter of Claim',
            'template_key' => 'letter_of_claim',
            'file_path' => $templatePath,
            'available_fields' => [],
            'active' => true,
        ]);

        $this->artisan('backup:sync-documents')->assertSuccessful();

        Storage::disk('sharepoint')->assertExists($genPath);
        Storage::disk('sharepoint')->assertExists($templatePath);
        $this->assertNotNull($generated->fresh()->backed_up_at);
        $this->assertNotNull($template->fresh()->backed_up_at);
    }
}
