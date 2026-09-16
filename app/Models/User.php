<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasReasonedActivityLog;
use App\Enums\UserRole;
use App\Support\Hub\HubAccess;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Lab404\Impersonate\Models\Impersonate;
use SensitiveParameter;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasReasonedActivityLog, LogsActivity, HasRoles, Impersonate, Notifiable;

    /**
     * Not logFillable(): 'password' is fillable but must never be written
     * into the audit trail, hashed or not — named fields only.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'email', 'role'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Deprecated: superseded by spatie/laravel-permission (tenant-scoped
            // roles via HasRoles). Kept populated, but no longer read by any
            // authorization logic — see MigrateUserRolesToPermissions.
            'role' => UserRole::class,
            // Deliberately not fillable — set only by CreateHubUser (true, on
            // a freshly generated temp password) and SetHubPassword (false,
            // once cleared). No form should ever mass-assign this.
            'must_change_password' => 'boolean',
            // Encrypted at rest via Laravel's own cast (not manual
            // encrypt()/decrypt() calls) — these are TOTP secrets and
            // recovery-code hashes, not display data. Also excluded from
            // serialization via #[Hidden] above, same as password.
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * True if the user holds $roleName in ANY tenant, ignoring the currently
     * active team context entirely. Needed only for the tenant-switcher
     * bootstrap problem: getTenants()/canAccessTenant() run before Filament
     * has resolved an active tenant, so the normal team-scoped hasRole()
     * can't yet answer "is this a director". Existing data assigns one role
     * name to a user across every tenant they belong to, so this agrees with
     * the team-scoped check once a tenant IS resolved. Temporarily disabling
     * team scoping on the registrar (rather than querying a team id) mirrors
     * the technique spatie's own HasRoles internals use for the same purpose.
     */
    public function hasRoleInAnyTeam(string $roleName, string $guard = 'web'): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $teamsWereEnabled = $registrar->teams;
        $registrar->teams = false;

        try {
            return $this->roles()
                ->where(config('permission.table_names.roles').'.name', $roleName)
                ->where(config('permission.table_names.roles').'.guard_name', $guard)
                ->exists();
        } finally {
            $registrar->teams = $teamsWereEnabled;
        }
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'user_tenant');
    }

    /**
     * A director can't be restricted, so they see and can switch into every
     * brand regardless of their user_tenant rows.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return $this->hasRoleInAnyTeam('director') ? Tenant::all() : $this->tenants;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->hasRoleInAnyTeam('director') || $this->tenants()->whereKey($tenant->getKey())->exists();
    }

    /**
     * Staff belong to the admin panel only. Without implementing
     * FilamentUser at all, Filament's Authenticate middleware allows access
     * only when app.env is `local` — i.e. everything worked in local
     * click-testing and every staff login would have 403'd in production
     * (audit finding F2).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'hub') {
            return $this->hasHubRole([HubAccess::ROLE_SALES, HubAccess::ROLE_DIRECTOR]);
        }

        return $panel->getId() === 'admin';
    }

    /**
     * True if the user holds any of $roleNames under HubAccess::TEAM_ID —
     * the Hub-panel equivalent of hasAnyRole(), scoped away from whichever
     * firm tenant (if any) happens to be ambient. See HubAccess's docblock
     * for why Hub roles live under a reserved team id rather than a real
     * tenant_id.
     *
     * @param  string|array<int, string>  $roleNames
     */
    public function hasHubRole(string|array $roleNames): bool
    {
        return HubAccess::withHubTeam(fn (): bool => $this->hasAnyRole($roleNames));
    }

    /**
     * The Hub-panel equivalent of can() — see hasHubRole()'s docblock for
     * why Hub permissions can't be checked with the plain team-scoped can().
     */
    public function hasHubPermission(string $permission): bool
    {
        return HubAccess::withHubTeam(fn (): bool => $this->can($permission));
    }

    /**
     * Overrides Lab404\Impersonate\Models\Impersonate's defaults (both
     * unconditionally true) — actual gating for Section 19's impersonation
     * feature. Only hub_director may initiate, and only a real firm user
     * (belongs to at least one tenant) is a valid target; a Hub-only staff
     * account has no tenant context to impersonate into.
     */
    public function canImpersonate(): bool
    {
        return $this->hasHubPermission(HubAccess::PERMISSION_IMPERSONATE);
    }

    public function canBeImpersonated(): bool
    {
        return $this->tenants()->exists();
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }
}
