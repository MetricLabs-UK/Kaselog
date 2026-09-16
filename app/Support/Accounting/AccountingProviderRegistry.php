<?php

namespace App\Support\Accounting;

use App\Enums\AccountingProviderKey;
use App\Support\Accounting\Xero\XeroAccountingProvider;
use InvalidArgumentException;

/**
 * The single place that maps a provider key to its implementation — adding
 * a second real provider is one new line here (plus the class implementing
 * AccountingProviderContract), not a change anywhere that already resolves
 * a provider by key.
 */
final class AccountingProviderRegistry
{
    /**
     * @var array<string, class-string<AccountingProviderContract>>
     */
    private const MAP = [
        'xero' => XeroAccountingProvider::class,
    ];

    public static function get(AccountingProviderKey $key): AccountingProviderContract
    {
        if (! $key->isRealProvider()) {
            throw new InvalidArgumentException("{$key->value} has no AccountingProviderContract implementation — check isRealProvider() before resolving one.");
        }

        $class = self::MAP[$key->value]
            ?? throw new InvalidArgumentException("No AccountingProviderContract registered for '{$key->value}'.");

        return app($class);
    }
}
