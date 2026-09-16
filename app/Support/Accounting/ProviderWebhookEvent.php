<?php

namespace App\Support\Accounting;

/**
 * One normalised event out of a provider's webhook payload — just enough
 * for the reconciliation path to work regardless of what the provider's own
 * payload looks like. externalOrgId (Xero's own tenantId, present on every
 * event) is what lets an *unmatched* invoice still be attributed to the
 * right Kase tenant via AccountingConnection.external_org_id, rather than
 * only ever being resolvable when the invoice ID happens to match.
 */
final class ProviderWebhookEvent
{
    public function __construct(
        public readonly string $externalInvoiceId,
        public readonly ?string $externalOrgId,
    ) {}
}
