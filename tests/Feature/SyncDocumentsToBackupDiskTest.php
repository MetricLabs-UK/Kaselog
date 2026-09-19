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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Storage::fake('s3')/'documents' — never a real Backblaze B2 write, which
 * makes a genuine network call the moment it's attempted. Storage::fake()
 * swaps the disk regardless of its configured driver, so this is safe with
 * blank/no AWS_* credentials, same as the rest of the suite avoids real
 * external services.
 *
 * BACKUP_DISKS is read directly via env() inside the command (not cached
 * config), so tests set/restore it with putenv()/$_ENV per test rather than
 * relying on whatever happens to be in the real .env on this machine.
 */
class SyncDocumentsToBackupDiskTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        putenv('BACKUP_DISKS');
        unset($_ENV['BACKUP_DISKS']);

        parent::tearDown();
    }

    private function setBackupDisks(string $value): void
    {
        putenv("BACKUP_DISKS={$value}");
        $_ENV['BACKUP_DISKS'] = $value;
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
        $this->setBackupDisks('local,s3');
        $document = $this->matterDocument(backedUpAt: null);

        $this->artisan('backup:sync-documents')->assertSuccessful();

        Storage::disk('s3')->assertExists($document->path);
        $this->assertSame('pdf contents', Storage::disk('s3')->get($document->path));
        $this->assertNotNull($document->fresh()->backed_up_at);
    }

    public function test_an_already_synced_unchanged_document_is_not_re_uploaded(): void
    {
        $this->setBackupDisks('local,s3');
        $document = $this->matterDocument(backedUpAt: now()->addMinute()->toDateTimeString());

        $this->artisan('backup:sync-documents')->assertSuccessful();

        Storage::disk('s3')->assertMissing($document->path);
    }

    public function test_a_document_changed_since_its_last_sync_is_re_uploaded(): void
    {
        $this->setBackupDisks('local,s3');
        $document = $this->matterDocument(backedUpAt: now()->subDay()->toDateTimeString());
        $document->touch(); // updated_at now after backed_up_at

        $this->artisan('backup:sync-documents')->assertSuccessful();

        Storage::disk('s3')->assertExists($document->path);
    }

    public function test_a_missing_source_file_fails_that_record_without_aborting_the_rest_and_notifies(): void
    {
        $this->setBackupDisks('local,s3');
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

        Storage::disk('s3')->assertExists($goodPath);
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
        $this->setBackupDisks('local,s3');
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

        Storage::disk('s3')->assertExists($genPath);
        Storage::disk('s3')->assertExists($templatePath);
        $this->assertNotNull($generated->fresh()->backed_up_at);
        $this->assertNotNull($template->fresh()->backed_up_at);
    }

    public function test_local_only_backup_disks_is_a_no_op_and_does_not_stamp_backed_up_at(): void
    {
        $this->setBackupDisks('local');
        $document = $this->matterDocument(backedUpAt: null);

        $this->artisan('backup:sync-documents')->assertSuccessful();

        $this->assertNull($document->fresh()->backed_up_at);
    }

    public function test_local_disk_is_skipped_explicitly_and_logged_alongside_a_real_destination(): void
    {
        Log::spy();

        $this->setBackupDisks('local,s3');
        $document = $this->matterDocument(backedUpAt: null);

        $this->artisan('backup:sync-documents')->assertSuccessful();

        Storage::disk('s3')->assertExists($document->path);
        $this->assertNotNull($document->fresh()->backed_up_at);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message): bool => str_contains($message, "skipping disk 'local'"))
            ->once();
    }
}
