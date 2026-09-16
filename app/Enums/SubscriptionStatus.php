<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Deliberately just two states, not a taxonomy of *why* a subscription ended
 * (defaulted, cancelled, suspended) — the grandfathering rule (see
 * TenantSubscription::startFor()) only cares *whether* cover was continuous,
 * not why it wasn't. The actual trigger that ends a subscription (billing
 * failure, a Director suspending the firm, voluntary cancellation) is a
 * process decision for later, layered on top of this.
 */
enum SubscriptionStatus: string implements HasLabel
{
    case Active = 'active';
    case Ended = 'ended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Ended => 'Ended',
        };
    }
}
