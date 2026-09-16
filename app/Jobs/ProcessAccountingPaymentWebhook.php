<?php

namespace App\Jobs;

use App\Enums\AccountingProviderKey;
use App\Enums\ReconciliationIssueReason;
use App\Models\AccountingConnection;
use App\Models\AccountingReconciliationIssue;
use App\Models\Invoice;
use App\Support\Accounting\ProviderWebhookEvent;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Provider-generic successor to ProcessXeroPayment (Section 20) — the same
 * job now handles any provider's "an invoice was paid" webhook event, not
 * just Xero's. The one thing that used to be a silent log-and-return when
 * an invoice id matched nothing is now a real AccountingReconciliationIssue,
 * per that section's sign-off: no silent failures.
 */
class ProcessAccountingPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AccountingProviderKey $provider,
        public readonly ProviderWebhookEvent $event,
    ) {}

    public function handle(): void
    {
        // The invoice id is globally unique and carries no tenant of its
        // own, so this lookup must bypass the tenant scope — then every
        // subsequent save/update needs CurrentTenant set to the invoice's
        // own tenant, or those writes would silently match zero rows.
        //
        // Fetched bare — NO eager loads. Relations go through the related
        // models' own fail-closed TenantScope, so loading them here, before
        // the tenant is set, caches everything as empty/null even though the
        // rows exist (audit finding F1). Same fetch-bare -> set tenant ->
        // load() order as SummarizeMatterDocument.
        $invoice = Invoice::allTenants()
            ->where('provider_invoice_id', $this->event->externalInvoiceId)
            ->first();

        if ($invoice === null) {
            $this->recordUnmatched();

            return;
        }

        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($invoice->tenant);

        try {
            $invoice->load('instalments.paymentPlan.matter');
            $invoice->markPaid();

            Log::info("Accounting webhook: invoice {$invoice->id} marked paid via {$this->provider->value}.");
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }

    private function recordUnmatched(): void
    {
        $connection = $this->event->externalOrgId !== null
            ? AccountingConnection::forExternalOrgId($this->provider, $this->event->externalOrgId)
            : null;

        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($connection?->tenant);

        try {
            Log::info("Accounting webhook: no instalment found for provider_invoice_id={$this->event->externalInvoiceId}.");

            AccountingReconciliationIssue::create([
                'tenant_id' => $connection?->tenant_id,
                'provider' => $this->provider,
                'external_invoice_id' => $this->event->externalInvoiceId,
                'reason' => ReconciliationIssueReason::WebhookUnmatched,
                // Explicit, not left to the column default: create()'s
                // in-memory model never re-reads a DB-level default after
                // insert, so AccountingReconciliationIssue::booted()'s
                // "if (! $issue->needs_review) return;" would otherwise see
                // null (falsy) here and silently skip the director
                // notification — found via a real test, not by inspection.
                'needs_review' => true,
                'payload' => ['external_org_id' => $this->event->externalOrgId],
            ]);
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }
}
