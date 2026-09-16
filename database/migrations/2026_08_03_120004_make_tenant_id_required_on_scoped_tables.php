<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::REQUIRED_TENANT_TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::REQUIRED_TENANT_TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->change();
            });
        }
    }
};
