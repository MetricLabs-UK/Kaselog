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
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 2 — the same job, generalized to BackupExportScope::WholeFirm.
 * Proves it bundles across every client in the firm (not just one), and at
 * a volume large enough that resolveMatters()'s cursor()-based streaming
 * actually gets exercised rather than trivially matching a single get().
 */
class GenerateWholeFirmBackupExportTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('exports');

        $this->setUpTenant();
    }

    private function makeClientWithMatter(string $firstName, string $lastName): Matter
    {
        $client = Client::create([
            'first_name' => $firstName, 'last_name' => $lastName, 'email' => strtolower("{$firstName}.{$lastName}@example.com"),
            'phone' => '07555123456', 'source' => ClientSource::Phone,
        ]);

        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);

        Storage::disk('documents')->put("matter-docs/{$matter->id}/doc.pdf", "contents for matter {$matter->id}");
        MatterDocument::create([
            'matter_id' => $matter->id, 'uploaded_by_type' => 'user', 'uploaded_by_id' => User::factory()->create()->id,
            'filename' => "doc-{$matter->id}.pdf", 'path' => "matter-docs/{$matter->id}/doc.pdf",
        ]);

        return $matter;
    }

    public function test_a_whole_firm_export_bundles_every_clients_matters(): void
    {
        $matterA = $this->makeClientWithMatter('Alice', 'Anderson');
        $matterB = $this->makeClientWithMatter('Bob', 'Baker');

        $export = BackupExport::create([
            'scope' => BackupExportScope::WholeFirm,
            'client_id' => null,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Pending,
        ]);

        (new GenerateBackupExport($export->id))->handle();

        $fresh = $export->fresh();
        $this->assertSame(BackupExportStatus::Completed, $fresh->status);

        $absolutePath = Storage::disk('exports')->path($fresh->file_path);
        $zip = new ZipArchive;
        $zip->open($absolutePath);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $csv = $zip->getFromName('matters.csv');
        $zip->close();

        $this->assertTrue(collect($names)->contains(fn (string $n) => str_ends_with($n, "doc-{$matterA->id}.pdf")));
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_ends_with($n, "doc-{$matterB->id}.pdf")));
        $this->assertStringContainsString('Alice Anderson', $csv);
        $this->assertStringContainsString('Bob Baker', $csv);
    }

    public function test_a_whole_firm_export_handles_many_matters_across_many_clients(): void
    {
        collect(range(1, 25))->each(fn (int $i) => $this->makeClientWithMatter("First{$i}", "Last{$i}"));

        $export = BackupExport::create([
            'scope' => BackupExportScope::WholeFirm,
            'client_id' => null,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Pending,
        ]);

        (new GenerateBackupExport($export->id))->handle();

        $fresh = $export->fresh();
        $this->assertSame(BackupExportStatus::Completed, $fresh->status);

        $absolutePath = Storage::disk('exports')->path($fresh->file_path);
        $zip = new ZipArchive;
        $zip->open($absolutePath);

        // 25 matters x 1 document each + matters.csv.
        $this->assertSame(26, $zip->numFiles);

        $csv = $zip->getFromName('matters.csv') ?: '';
        $zip->close();

        for ($i = 1; $i <= 25; $i++) {
            $this->assertStringContainsString("First{$i} Last{$i}", $csv);
        }
    }
}
