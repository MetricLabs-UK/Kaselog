<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * An Invoice's own lifecycle — separate from InstalmentStatus (which tracks
 * whether a specific instalment has been paid) and independent of whichever
 * source(s) (Instalment or TimeEntry rows) fed it. Draft covers a
 * time-entry bundle awaiting review; an instalment-sourced invoice normally
 * skips straight to Sent, since that flow creates and sends in one action.
 */
enum InvoiceStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Paid => 'Paid',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'warning',
            self::Paid => 'success',
        };
    }
}
