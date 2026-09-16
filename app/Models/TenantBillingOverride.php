<?php

namespace App\Models;

use App\Enums\BillableItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-firm negotiated rate — see the creating migration's docblock for why
 * this has no BelongsToTenant scope, and App\Support\Billing\RateResolver
 * for how it takes precedence over StandardRate.
 */
#[Fillable([
    'tenant_id',
    'billable_item',
    'amount',
    'note',
    'created_by',
])]
class TenantBillingOverride extends Model
{
    protected function casts(): array
    {
        return [
            'billable_item' => BillableItem::class,
            'amount' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Sets (or replaces) the negotiated rate for one (tenant, item) pair —
     * the one write path, so the unique (tenant_id, billable_item) index is
     * never fought over by two different call sites doing their own
     * updateOrCreate().
     */
    public static function setOverride(
        Tenant $tenant,
        BillableItem $item,
        float $amount,
        ?string $note,
        ?User $createdBy,
    ): self {
        return static::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'billable_item' => $item],
            ['amount' => $amount, 'note' => $note, 'created_by' => $createdBy?->id],
        );
    }

    public static function removeOverride(Tenant $tenant, BillableItem $item): void
    {
        static::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_item', $item)
            ->delete();
    }
}
