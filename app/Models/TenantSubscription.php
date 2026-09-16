<?php

namespace App\Models;

use App\Enums\BillingCadence;
use App\Enums\PurchaseMode;
use App\Enums\SeatPurchaseType;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One continuous period of paid cover for a tenant — see the creating
 * migration's docblock for why a lapse-and-resubscribe is a new row rather
 * than reusing the old one (that's what makes grandfathering not surviving a
 * gap a structural property of the schema, not a rule computed at query
 * time).
 */
#[Fillable([
    'tenant_id',
    'purchase_mode',
    'billing_cadence',
    'status',
    'started_at',
    'ended_at',
])]
class TenantSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'purchase_mode' => PurchaseMode::class,
            'billing_cadence' => BillingCadence::class,
            'status' => SubscriptionStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function seatPurchases(): HasMany
    {
        return $this->hasMany(TenantSeatPurchase::class);
    }

    /**
     * The one way a subscription is ever started. Ends any existing active
     * subscription for this tenant first — enforced here rather than a DB
     * constraint (a partial unique index on "one active row per tenant"
     * isn't natively expressible the same way across MySQL and the test
     * suite's SQLite), so this method is the single point that invariant
     * actually holds at. Never construct a TenantSubscription directly.
     */
    public static function startFor(Tenant $tenant, PurchaseMode $mode, BillingCadence $cadence): self
    {
        static::currentFor($tenant)?->end();

        return static::query()->create([
            'tenant_id' => $tenant->id,
            'purchase_mode' => $mode,
            'billing_cadence' => $cadence,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
        ]);
    }

    public static function currentFor(Tenant $tenant): ?self
    {
        return static::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', SubscriptionStatus::Active)
            ->latest('started_at')
            ->first();
    }

    public function end(): void
    {
        $this->update([
            'status' => SubscriptionStatus::Ended,
            'ended_at' => now(),
        ]);
    }

    /**
     * Refuses on an ended subscription — a lapsed subscription's seat ledger
     * is closed permanently; a firm buying seats again means starting a new
     * subscription via startFor(), which is what actually resets them to
     * the current rate. Without this guard, nothing would stop a stray call
     * site from quietly re-adding seats to a dead subscription instead.
     */
    public function recordSeatPurchase(SeatPurchaseType $type, int $quantity, float $rateApplied): TenantSeatPurchase
    {
        if ($this->status === SubscriptionStatus::Ended) {
            throw new LogicException('Cannot record a seat purchase against an ended subscription — start a new one via TenantSubscription::startFor() instead.');
        }

        return $this->seatPurchases()->create([
            'purchase_type' => $type,
            'quantity' => $quantity,
            'rate_applied' => $rateApplied,
            'purchased_at' => now(),
        ]);
    }

    public function totalSeats(): int
    {
        return $this->seatPurchases->sum(fn (TenantSeatPurchase $purchase) => $purchase->seatsGranted());
    }
}
