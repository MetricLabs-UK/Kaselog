<?php

namespace App\Models;

use App\Support\Tenancy\ReservedSlugs;
use Database\Factories\TenantFactory;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'slug',
    'is_active',
    'logo_path',
    'tagline',
    'reference_prefix',
    'settings',
    'parent_tenant_id',
    'legal_entity_name',
    'company_number',
    'sra_number',
    'firm_address',
    'firm_phone',
    'firm_email',
    'pools_billing_with_parent',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * There is no tenant CRUD UI (yet) — tenants come from seeders and
     * tinker — so the model itself is the one enforcement point for
     * reserved slugs. See ReservedSlugs for why a reserved slug would
     * permanently break that tenant's portal URLs.
     */
    protected static function booted(): void
    {
        static::saving(function (Tenant $tenant): void {
            if (filled($tenant->slug) && ReservedSlugs::isReserved($tenant->slug)) {
                throw new InvalidArgumentException(
                    "Tenant slug \"{$tenant->slug}\" is reserved by a top-level route prefix — its portal URLs would be shadowed by that route. See App\\Support\\Tenancy\\ReservedSlugs."
                );
            }
        });

        // Auto-provision the four standard roles (and their permissions) for
        // every new tenant, so a firm is immediately usable the moment it's
        // created. Note: DatabaseSeeder runs WithoutModelEvents, so a tenant
        // created under that trait would skip this — not a concern today
        // (DatabaseSeeder creates no tenants of its own), but worth knowing
        // if that changes. The 3 real tenants that predate this package were
        // inserted via raw DB queries in a migration (never fired this
        // event) — see TenantRoleSeeder's other call sites for those.
        static::created(function (Tenant $tenant): void {
            TenantRoleSeeder::seed($tenant);
        });
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'pools_billing_with_parent' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tenant');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function accountingConnection(): HasOne
    {
        return $this->hasOne(AccountingConnection::class);
    }

    public function parentTenant(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_tenant_id');
    }

    /**
     * The legal entity actually regulating this brand — itself, unless it's
     * a trading style of a parent (e.g. The Motoring Lawyers → Lostock Legal
     * Solicitors Ltd). Guarded against a self-reference loop as cheap
     * insurance, even though the real hierarchy is only ever two levels.
     */
    public function legalEntity(): self
    {
        $parent = $this->parentTenant;

        return ($parent && $parent->id !== $this->id) ? $parent : $this;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function billingOverrides(): HasMany
    {
        return $this->hasMany(TenantBillingOverride::class);
    }

    public function addonSubscriptions(): HasMany
    {
        return $this->hasMany(TenantAddonSubscription::class);
    }

    public function currentSubscription(): ?TenantSubscription
    {
        return TenantSubscription::currentFor($this);
    }

    /**
     * The tenant whose subscription/seats this one's usage actually bills
     * against — itself, unless pools_billing_with_parent is set (a bespoke,
     * manually-arranged trading-brand setup, not a self-service toggle — see
     * the pools_billing_with_parent migration). Mirrors legalEntity()'s
     * shape and same self-reference guard.
     */
    public function billingTenant(): self
    {
        if (! $this->pools_billing_with_parent) {
            return $this;
        }

        $parent = $this->parentTenant;

        return ($parent && $parent->id !== $this->id) ? $parent : $this;
    }

    public static function findByRetellAgentId(string $agentId): ?self
    {
        return static::query()
            ->where('settings->retell_agent_id', $agentId)
            ->first();
    }
}
