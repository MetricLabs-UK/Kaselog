<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Console\Command;

/**
 * One-time historical backfill: for every existing User, reads their legacy
 * `role` enum column and assigns the equivalent spatie role, scoped to every
 * tenant they belong to (a director already has a user_tenant row for every
 * real tenant per the 2026_08_03_120003 seed migration). Also (re)seeds the
 * four standard roles for every tenant, since the 3 pre-existing tenants
 * were inserted via raw DB queries and never fired Tenant's own auto-seed
 * hook.
 *
 * Idempotent — syncRoles() replaces rather than accumulates, so safe to
 * re-run. Deliberately a manual command, not wired into routine db:seed —
 * this is a one-time backfill for pre-existing enum data, not part of
 * ordinary fresh-install seeding (which already assigns roles directly, see
 * DatabaseSeeder).
 */
class MigrateUserRolesToPermissions extends Command
{
    protected $signature = 'app:migrate-user-roles-to-permissions';

    protected $description = 'Backfill spatie/laravel-permission role assignments from the legacy users.role enum column';

    public function handle(): int
    {
        Tenant::all()->each(function (Tenant $tenant): void {
            TenantRoleSeeder::seed($tenant);
            $this->line("Seeded roles for tenant: {$tenant->name}");
        });

        $migrated = 0;

        User::all()->each(function (User $user) use (&$migrated): void {
            if ($user->role === null) {
                return;
            }

            foreach ($user->tenants as $tenant) {
                CurrentTenant::set($tenant);
                $user->syncRoles([$user->role->value]);
            }

            $migrated++;
        });

        CurrentTenant::clear();

        $this->info("Migrated {$migrated} user(s) from the legacy role column to spatie roles.");

        return self::SUCCESS;
    }
}
