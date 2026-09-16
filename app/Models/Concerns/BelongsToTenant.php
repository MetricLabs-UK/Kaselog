<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            if (filled($model->tenant_id)) {
                return;
            }

            foreach ($model->inheritsTenantIdFrom() as $relation) {
                // Bypass every global scope on the parent lookup itself (not
                // just tenant) — e.g. Lead's ExcludeConvertedLeadsScope must
                // not hide a legitimate parent just because it has since
                // converted. Inheritance must work even with no ambient
                // tenant set.
                $related = $model->{$relation}()->withoutGlobalScopes()->first();

                if ($related?->tenant_id) {
                    $model->tenant_id = $related->tenant_id;

                    return;
                }
            }

            $model->tenant_id = CurrentTenant::id();
        });
    }

    /**
     * Relation name(s) to try, in order, for inheriting tenant_id from a
     * parent record when it isn't already set. Override on models with a
     * parent (e.g. Instalment inherits from `paymentPlan`). Falls back to
     * CurrentTenant::id() if none resolve.
     *
     * @return array<int, string>
     */
    protected function inheritsTenantIdFrom(): array
    {
        return [];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Escape hatch for the deliberate cross-tenant cases: the per-tenant job
     * loop and the director's "all tenants combined" dashboard view.
     */
    public static function allTenants(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
