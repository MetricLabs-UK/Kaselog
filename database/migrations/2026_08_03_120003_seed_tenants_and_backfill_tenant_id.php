<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BACKFILL_TABLES = [
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
        $now = now();

        $tenantIds = [];

        foreach ([
            ['name' => 'Lostock Legal', 'slug' => 'lostock-legal', 'reference_prefix' => 'LL'],
            ['name' => 'The Motoring Lawyers', 'slug' => 'the-motoring-lawyers', 'reference_prefix' => 'TML'],
            ['name' => 'The Immigration Lawyers UK', 'slug' => 'the-immigration-lawyers-uk', 'reference_prefix' => 'TIL'],
        ] as $tenant) {
            $tenantIds[$tenant['slug']] = DB::table('tenants')->insertGetId([
                ...$tenant,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $lostockLegalId = $tenantIds['lostock-legal'];

        // Every existing scoped row predates multi-tenancy — it's Alex's
        // original single-tenant firm, Lostock Legal.
        foreach (self::BACKFILL_TABLES as $table) {
            DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $lostockLegalId]);
        }

        // Every existing user gets Lostock Legal access (the firm they
        // already worked in); directors additionally get every brand.
        $users = DB::table('users')->select('id', 'role')->get();

        foreach ($users as $user) {
            DB::table('user_tenant')->insertOrIgnore([
                'user_id' => $user->id,
                'tenant_id' => $lostockLegalId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($user->role === 'director') {
                foreach ($tenantIds as $slug => $tenantId) {
                    if ($tenantId === $lostockLegalId) {
                        continue;
                    }

                    DB::table('user_tenant')->insertOrIgnore([
                        'user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('user_tenant')->truncate();

        foreach (self::BACKFILL_TABLES as $table) {
            DB::table($table)->update(['tenant_id' => null]);
        }

        DB::table('tenants')->whereIn('slug', [
            'lostock-legal',
            'the-motoring-lawyers',
            'the-immigration-lawyers-uk',
        ])->delete();
    }
};
