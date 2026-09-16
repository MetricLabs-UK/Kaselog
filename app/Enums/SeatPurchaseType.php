<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SeatPurchaseType: string implements HasLabel
{
    case Individual = 'individual';
    case BulkBlockOf5 = 'bulk_block_of_5';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'Individual seat',
            self::BulkBlockOf5 => 'Bulk block of 5',
        };
    }

    /**
     * How many seats one unit of this purchase type actually grants —
     * the single place that "a block is 5 seats" is ever written down.
     */
    public function seatsPerUnit(): int
    {
        return match ($this) {
            self::Individual => 1,
            self::BulkBlockOf5 => 5,
        };
    }

    /**
     * Which BillableItem's rate applies to a purchase of this type — the
     * single place that mapping is written down, so a seat-purchase form
     * and RateResolver can never disagree on which price to use.
     */
    public function billableItem(): BillableItem
    {
        return match ($this) {
            self::Individual => BillableItem::SeatIndividual,
            self::BulkBlockOf5 => BillableItem::SeatBulkBlockOf5,
        };
    }
}
