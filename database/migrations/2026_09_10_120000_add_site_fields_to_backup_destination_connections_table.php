<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A connection now has two stages: OAuth-connected (has tokens) and
     * site-selected (has a drive_id) — see BackupDestinationConnection::
     * hasSiteSelected() and SharePointBackupProvider::selectSite(). Only
     * meaningful for the sharepoint provider today; left nullable rather
     * than a separate table since Google Drive (Phase 4) may or may not
     * need an equivalent "pick a shared drive" step of its own.
     */
    public function up(): void
    {
        Schema::table('backup_destination_connections', function (Blueprint $table) {
            $table->string('site_id')->nullable()->after('provider');
            $table->string('site_name')->nullable()->after('site_id');
            $table->string('drive_id')->nullable()->after('site_name');
        });
    }

    public function down(): void
    {
        Schema::table('backup_destination_connections', function (Blueprint $table) {
            $table->dropColumn(['site_id', 'site_name', 'drive_id']);
        });
    }
};
