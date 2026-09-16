<?php

namespace Tests\Feature\Console;

use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Enums\BackupExportStatus;
use App\Models\BackupExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CleanExpiredBackupExportsTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_it_deletes_the_file_behind_an_expired_export_but_keeps_the_row(): void
    {
        Storage::fake('exports');
        $this->setUpTenant();

        Storage::disk('exports')->put('1/expired.zip', 'old backup contents');
        $expired = BackupExport::create([
            'scope' => BackupExportScope::SingleClient,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Completed,
            'file_path' => '1/expired.zip',
            'expires_at' => now()->subDay(),
        ]);

        Storage::disk('exports')->put('1/fresh.zip', 'fresh backup contents');
        $fresh = BackupExport::create([
            'scope' => BackupExportScope::SingleClient,
            'destination' => BackupExportDestination::Download,
            'status' => BackupExportStatus::Completed,
            'file_path' => '1/fresh.zip',
            'expires_at' => now()->addDays(6),
        ]);

        $this->artisan('backup:clean-expired-exports')->assertExitCode(0);

        Storage::disk('exports')->assertMissing('1/expired.zip');
        Storage::disk('exports')->assertExists('1/fresh.zip');

        $this->assertDatabaseHas('backup_exports', ['id' => $expired->id, 'file_path' => null]);
        $this->assertDatabaseHas('backup_exports', ['id' => $fresh->id, 'file_path' => '1/fresh.zip']);
    }
}
