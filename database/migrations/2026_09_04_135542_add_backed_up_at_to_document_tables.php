<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 12 (Backup & Disaster Recovery) — tracks which files
 * App\Console\Commands\SyncDocumentsToSharePoint has already pushed to the
 * SharePoint destination, so its nightly run only uploads what's new or
 * changed since the last one rather than re-uploading everything every
 * night. Covers all three of this app's file-backed models under the
 * 'documents' disk (config/filesystems.php) — matter_documents was the one
 * named "the documents table" when this was scoped, but generated_documents
 * and precedent_templates are exactly the same shape of problem (a real
 * uploaded/generated file on disk with no independent backup tracking) and
 * would otherwise be silently missed by an incremental sync that only
 * looked at one of the three.
 */
return new class extends Migration
{
    private const TABLES = ['matter_documents', 'generated_documents', 'precedent_templates'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('backed_up_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('backed_up_at');
            });
        }
    }
};
