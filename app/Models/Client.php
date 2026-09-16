<?php

namespace App\Models;

use App\Concerns\HasReasonedActivityLog;
use App\Enums\ClientSource;
use App\Enums\PortalStatus;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ClientFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// ni_number is UK identity data — same encrypted-at-rest treatment as
// User.app_authentication_secret (see that model): an `encrypted` cast plus
// exclusion from array/json serialization, so it never leaks into an API
// response or a debug dump even though (unlike the TOTP secret) staff do
// type it directly into the normal Client form.
#[Fillable([
    'first_name',
    'last_name',
    'email',
    'phone',
    'address',
    'date_of_birth',
    'ni_number',
    'source',
    'notes',
    'portal_token',
    'portal_enabled',
    'portal_last_login',
    'locked',
    'director_only',
    'provider_contact_id',
])]
#[Hidden(['ni_number'])]
class Client extends Model implements AuthenticatableContract, FilamentUser, HasName, HasTenants
{
    /** @use HasFactory<ClientFactory> */
    use Archivable, Authenticatable, BelongsToTenant, HasFactory, HasReasonedActivityLog, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('clients')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'source' => ClientSource::class,
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'ni_number' => 'encrypted',
            'portal_enabled' => 'boolean',
            'portal_last_login' => 'datetime',
            'locked' => 'boolean',
            'director_only' => 'boolean',
        ];
    }

    /**
     * A client belongs to exactly one tenant directly (no pivot, unlike
     * staff Users) — the portal has no cross-brand access to switch between.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return $this->tenant ? collect([$this->tenant]) : collect();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->tenant_id === $tenant->getKey();
    }

    /**
     * portal_enabled and locked are the staff-facing controls for cutting
     * off a client's portal access — they must actually gate the auth path,
     * not just display in admin (audit finding F5). Filament checks
     * canAccessPanel() on every authenticated request, so flipping either
     * flag locks the client out on their next request, not just at their
     * next login.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal'
            && $this->portal_enabled
            && ! $this->locked;
    }

    /**
     * No "remember me" support for the portal (no remember_token column, and
     * not in scope) — an empty name tells the session guard to skip it.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function callNotes(): HasMany
    {
        return $this->hasMany(CallNote::class);
    }

    public function backupExports(): HasMany
    {
        return $this->hasMany(BackupExport::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Filament's user menu resolves a display name via getUserName(), which
     * falls back to a `name` attribute clients don't have — without this,
     * any authenticated portal page render throws a TypeError.
     */
    public function getFilamentName(): string
    {
        return $this->full_name;
    }

    public function getPortalStatusAttribute(): PortalStatus
    {
        return match (true) {
            $this->portal_enabled => PortalStatus::Active,
            filled($this->portal_token) => PortalStatus::Pending,
            default => PortalStatus::NotInvited,
        };
    }
}
