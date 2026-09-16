<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BillingCadence: string implements HasLabel
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Annual => 'Annual',
        };
    }
}
