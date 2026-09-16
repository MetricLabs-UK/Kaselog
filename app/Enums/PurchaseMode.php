<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PurchaseMode: string implements HasLabel
{
    case FlatFirmWide = 'flat_firm_wide';
    case SeatBased = 'seat_based';

    public function getLabel(): string
    {
        return match ($this) {
            self::FlatFirmWide => 'Flat, firm-wide (unlimited seats)',
            self::SeatBased => 'Seat-based',
        };
    }
}
