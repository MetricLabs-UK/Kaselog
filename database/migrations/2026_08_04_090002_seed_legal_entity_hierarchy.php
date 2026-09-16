<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $lostockLegalId = DB::table('tenants')->where('slug', 'lostock-legal')->value('id');

        if (! $lostockLegalId) {
            return;
        }

        DB::table('tenants')->where('id', $lostockLegalId)->update([
            'legal_entity_name' => 'Lostock Legal Solicitors Ltd',
            'company_number' => '15479870',
            'sra_number' => '8007582',
        ]);

        DB::table('tenants')
            ->whereIn('slug', ['the-motoring-lawyers', 'the-immigration-lawyers-uk'])
            ->update(['parent_tenant_id' => $lostockLegalId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tenants')
            ->whereIn('slug', ['the-motoring-lawyers', 'the-immigration-lawyers-uk'])
            ->update(['parent_tenant_id' => null]);

        DB::table('tenants')->where('slug', 'lostock-legal')->update([
            'legal_entity_name' => null,
            'company_number' => null,
            'sra_number' => null,
        ]);
    }
};
