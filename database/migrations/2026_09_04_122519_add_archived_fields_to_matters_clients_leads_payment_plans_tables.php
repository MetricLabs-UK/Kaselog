<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 13 (Archive System). Deliberately separate from `status` —
 * archiving is an additional action on top of whatever status a record
 * already has (e.g. a Matter can be Closed and not archived, or archived
 * while still Active), not a replacement for it. See App\Models\Concerns\
 * Archivable and App\Models\Scopes\ExcludeArchivedScope, which follow the
 * same global-scope pattern ExcludeConvertedLeadsScope already established
 * for Lead — not Eloquent's built-in SoftDeletes, which this app doesn't use
 * anywhere.
 */
return new class extends Migration
{
    private const TABLES = ['matters', 'clients', 'leads', 'payment_plans'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
                $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('archived_by');
                $table->dropColumn('archived_at');
            });
        }
    }
};
