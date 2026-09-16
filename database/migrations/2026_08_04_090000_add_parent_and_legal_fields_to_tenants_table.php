<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('parent_tenant_id')->nullable()->after('id')
                ->constrained('tenants')->restrictOnDelete();
            $table->string('legal_entity_name')->nullable();
            $table->string('company_number')->nullable();
            $table->string('sra_number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_tenant_id');
            $table->dropColumn(['legal_entity_name', 'company_number', 'sra_number']);
        });
    }
};
