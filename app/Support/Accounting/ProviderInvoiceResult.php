<?php

namespace App\Support\Accounting;

/**
 * What a provider hands back after actually creating an invoice — enough to
 * populate Instalment.provider_invoice_id and show a human-readable number,
 * without callers needing to know the shape of any one provider's SDK
 * response object.
 */
final class ProviderInvoiceResult
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $number,
    ) {}
}
