<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that will end up with a required tenant_id once backfilled.
     * Added nullable here; a later migration flips them to NOT NULL after
     * the data migration backfills existing rows.
     */
    private const REQUIRED_TENANT_TABLES = [
        'clients',
        'matters',
        'leads',
        'precedent_templates',
        'payment_plans',
        'instalments',
        'chase_logs',
        'nurture_sequences',
        'matter_documents',
        'matter_messages',
        'client_requests',
        'time_entries',
        'generated_documents',
        'call_notes',
    ];

    /**
     * Tables where tenant isn't always resolvable, so tenant_id stays
     * permanently nullable.
     */
    private const OPTIONAL_TENANT_TABLES = [
        'retell_call_logs',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::REQUIRED_TENANT_TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained();
            });
        }

        foreach (self::OPTIONAL_TENANT_TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        Schema::connection(config('activitylog.database_connection'))->table(
            config('activitylog.table_name'),
            function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([...self::REQUIRED_TENANT_TABLES, ...self::OPTIONAL_TENANT_TABLES] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }

        Schema::connection(config('activitylog.database_connection'))->table(
            config('activitylog.table_name'),
            function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            }
        );
    }
};
