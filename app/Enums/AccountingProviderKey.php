<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which accounting integration (if any) a firm has chosen — the value stored
 * on accounting_connections.provider. 'Manual' is itself a real, recorded
 * choice (a row with no tokens), distinct from no row at all ("never
 * configured") — see AccountingConnection's docblock for why that
 * distinction matters for the Integrations list.
 *
 * Xero is the only real implementation today (App\Support\Accounting\
 * Xero\XeroAccountingProvider). Adding Sage/QuickBooks/FreeAgent later means
 * a new case here, a new AccountingProviderContract implementation, and a
 * new AccountingProviderRegistry entry — nothing about this enum's existing
 * cases, or any code that already switches on them, needs to change.
 */
enum AccountingProviderKey: string implements HasLabel
{
    case Xero = 'xero';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Xero => 'Xero',
            self::Manual => 'No accounting software (manual)',
        };
    }

    /**
     * Whether this key represents a real, wired-up integration (as opposed
     * to the "manual" opt-out) — the single place "is this a real provider"
     * gets decided, so a future Sage/QuickBooks/FreeAgent case can't be
     * missed from one of the checks that needs it.
     */
    public function isRealProvider(): bool
    {
        return $this !== self::Manual;
    }
}
