<?php

namespace App\Models;

use App\Enums\SeatPurchaseType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a subscription's seat ledger — see the creating migration's
 * docblock for why this is a ledger rather than a counter, and why
 * rate_applied is a frozen snapshot rather than a live rate lookup.
 */
#[Fillable([
    'tenant_subscription_id',
    'purchase_type',
    'quantity',
    'rate_applied',
    'purchased_at',
])]
class TenantSeatPurchase extends Model
{
    protected function casts(): array
    {
        return [
            'purchase_type' => SeatPurchaseType::class,
            'quantity' => 'integer',
            'rate_applied' => 'decimal:2',
            'purchased_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function seatsGranted(): int
    {
        return $this->quantity * $this->purchase_type->seatsPerUnit();
    }
}
