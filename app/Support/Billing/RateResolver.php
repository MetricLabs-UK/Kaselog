<?php

namespace App\Support\Billing;

use App\Enums\BillableItem;
use App\Models\StandardRate;
use App\Models\Tenant;
use App\Models\TenantBillingOverride;

/**
 * The one place "what does this firm actually pay for X" gets decided:
 * a negotiated TenantBillingOverride always wins over the global
 * StandardRate. Every future billing/invoicing code path must resolve rates
 * through here rather than querying either table directly, or a negotiated
 * deal could silently be bypassed by a call site that forgot to check for
 * one.
 *
 * Returns null (never throws) when neither an override nor a standard rate
 * is set — "not yet priced" is a real, current state (Section 18 item 4's
 * figures are still TBD) that calling code must handle explicitly rather
 * than have masked by a fallback default.
 */
final class RateResolver
{
    public static function rateFor(Tenant $tenant, BillableItem $item): ?float
    {
        $override = TenantBillingOverride::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_item', $item)
            ->value('amount');

        if ($override !== null) {
            return (float) $override;
        }

        $standard = StandardRate::query()
            ->where('billable_item', $item)
            ->value('amount');

        return $standard !== null ? (float) $standard : null;
    }
}
