<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates (or promotes an existing) Hub user. Mirrors DatabaseSeeder's
 * production-safe pattern for the one seeded firm director: a random
 * password generated and printed to the console once, never hardcoded,
 * stored in plaintext, or emailed. See CreateHubUser's own docblock note
 * below re: forced password change on first login — not wired up yet.
 */
class CreateHubUser extends Command
{
    protected $signature = 'hub:create-user {email} {--name=} {--role=hub_director}';

    protected $description = 'Create a Hub user (or grant an existing account a Hub role)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->option('role');

        if (! array_key_exists($role, HubRoleSeeder::ROLE_GRANTS)) {
            $this->error("Unknown Hub role \"{$role}\". Valid roles: ".implode(', ', array_keys(HubRoleSeeder::ROLE_GRANTS)));

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"{$email}\" is not a valid email address.");

            return self::FAILURE;
        }

        // Idempotent — makes it safe to (re)run for the same person, and
        // covers granting a Hub role to someone who's already a firm user.
        HubRoleSeeder::seed();

        $user = User::where('email', $email)->first();

        if ($user) {
            $this->info("User {$email} already exists — granting the {$role} Hub role, password left unchanged.");
        } else {
            $name = $this->option('name') ?: Str::headline(Str::before($email, '@'));
            $password = Str::password(24);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            // Not mass-assignable (see User::casts()) — set directly, and
            // only on this branch: an existing account's password wasn't
            // just handed to them, so it must never be force-reset here.
            $user->forceFill(['must_change_password' => true])->save();

            $this->warn("Created Hub user: {$email}");
            $this->warn("Generated password: {$password}");
            $this->warn('Copy it now and log in to change it — it will not be shown again.');
            $this->comment('They will be required to set a new password on first login.');
        }

        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        $this->info("Granted Hub role \"{$role}\" to {$email}.");

        return self::SUCCESS;
    }
}
