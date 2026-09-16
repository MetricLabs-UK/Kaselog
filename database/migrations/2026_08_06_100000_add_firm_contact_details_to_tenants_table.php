<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Firm contact details join the legal identity fields on the tenant
     * (audit finding F19) — previously they came from global env config, so
     * every brand's generated documents carried the same (first brand's)
     * address/phone/email. Like legal_entity_name/company_number/sra_number,
     * they belong to the legal entity: children (trading styles) resolve
     * them through Tenant::legalEntity(), so only root tenants are
     * backfilled here — with the same env values (and defaults) the old
     * config supplied, preserving what documents rendered before this
     * migration. env() is read directly because the kaselog.firm_* config
     * keys are removed in the same change.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('firm_address')->nullable();
            $table->string('firm_phone')->nullable();
            $table->string('firm_email')->nullable();
        });

        DB::table('tenants')->whereNull('parent_tenant_id')->update([
            'firm_address' => env('FIRM_ADDRESS', '1 Example Street, Manchester, M1 1AA'),
            'firm_phone' => env('FIRM_PHONE', '0161 000 0000'),
            'firm_email' => env('FIRM_EMAIL', 'info@kaselog.example'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['firm_address', 'firm_phone', 'firm_email']);
        });
    }
};
