<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = Str::password(24);

        // 'role' stays populated for backward compatibility, but is no longer
        // read by any authorization logic — see TenantRoleSeeder/assignRole
        // below, the actual source of truth post-migration.
        $director = User::create([
            'name' => 'Alex',
            'email' => 'ar@lostocklegal.com',
            'password' => $password,
            'role' => UserRole::Director,
        ]);

        // The tenants table is populated by migration
        // 2026_08_03_120003_seed_tenants_and_backfill_tenant_id (raw DB
        // inserts, so Tenant::booted()'s auto-seed hook never fired for
        // them) — seed their roles here, and give the freshly-created
        // director access to, and the director role in, every one of them,
        // matching that same migration's "directors get every brand" rule.
        Tenant::all()->each(function (Tenant $tenant) use ($director): void {
            TenantRoleSeeder::seed($tenant);

            $director->tenants()->syncWithoutDetaching($tenant);

            CurrentTenant::set($tenant);
            $director->assignRole('director');
        });
        CurrentTenant::clear();

        $this->call(HubRoleSeeder::class);
        $this->call(BillingRateSeeder::class);

        // The plaintext password only ever exists here — it isn't stored or logged.
        $this->command?->warn("Seeded director account: {$director->email}");
        $this->command?->warn("Generated password: {$password}");
        $this->command?->warn('Copy it now and log in to change it — it will not be shown again.');

        $this->call(PrecedentTemplateSeeder::class);
        $this->call(ExampleDataSeeder::class);
    }
}
