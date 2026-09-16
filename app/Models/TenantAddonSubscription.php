<?php

namespace App\Models;

use App\Enums\BillableItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Enable/disable history for one metered add-on on one tenant — see the
 * creating migration's docblock for why this keeps history rather than
 * updating a single row in place.
 */
#[Fillable([
    'tenant_id',
    'billable_item',
    'enabled_at',
    'disabled_at',
])]
class TenantAddonSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'billable_item' => BillableItem::class,
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function enable(Tenant $tenant, BillableItem $item): self
    {
        self::guardIsAddon($item);

        if (self::isEnabledFor($tenant, $item)) {
            return self::latestFor($tenant, $item);
        }

        return static::query()->create([
            'tenant_id' => $tenant->id,
            'billable_item' => $item,
            'enabled_at' => now(),
        ]);
    }

    public static function disable(Tenant $tenant, BillableItem $item): void
    {
        self::latestFor($tenant, $item)?->update(['disabled_at' => now()]);
    }

    public static function isEnabledFor(Tenant $tenant, BillableItem $item): bool
    {
        $latest = self::latestFor($tenant, $item);

        return $latest !== null && $latest->disabled_at === null;
    }

    private static function latestFor(Tenant $tenant, BillableItem $item): ?self
    {
        return static::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_item', $item)
            ->latest('id')
            ->first();
    }

    private static function guardIsAddon(BillableItem $item): void
    {
        if (! $item->isAddon()) {
            throw new InvalidArgumentException("{$item->value} is not an add-on — tenant_addon_subscriptions is only for BillableItem::addonItems().");
        }
    }
}
