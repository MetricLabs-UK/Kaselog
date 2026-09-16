<?php

namespace Tests\Feature\Jobs;

use App\Enums\AccountingProviderKey;
use App\Enums\ClientSource;
use App\Enums\InstalmentStatus;
use App\Enums\MatterStatus;
use App\Enums\ReconciliationIssueReason;
use App\Jobs\ProcessAccountingPaymentWebhook;
use App\Models\AccountingConnection;
use App\Models\AccountingReconciliationIssue;
use App\Models\Client;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\PaymentPlan;
use App\Support\Accounting\ProviderWebhookEvent;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 20 — provider-generic successor to the old ProcessXeroPaymentTest.
 * The one real behavioural change: an unmatched invoice id used to be a
 * silent log-and-return; it must now always produce an
 * AccountingReconciliationIssue, attributed to a tenant when the event's
 * org id resolves to one.
 */
class ProcessAccountingPaymentWebhookTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private Matter $matter;

    private Instalment $instalment;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpTenant();

        $client = Client::create([
            'first_name' => 'Webhook', 'last_name' => 'Client', 'email' => 'webhook@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
        ]);

        $this->matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Motoring',
            'status' => MatterStatus::Suspended,
        ]);

        $plan = PaymentPlan::create([
            'matter_id' => $this->matter->id,
            'total_amount' => 1000,
            'deposit_amount' => 100,
            'notes' => '',
        ]);

        $this->instalment = Instalment::create([
            'payment_plan_id' => $plan->id,
            'amount' => 100,
            'due_date' => today()->subDays(30)->toDateString(),
            'status' => InstalmentStatus::Overdue,
        ]);
        $this->instalment->markSentToProvider('INV-1');
    }

    private function runJobAsFreshWorker(ProviderWebhookEvent $event): void
    {
        CurrentTenant::clear();

        try {
            (new ProcessAccountingPaymentWebhook(AccountingProviderKey::Xero, $event))->handle();
        } finally {
            CurrentTenant::set($this->tenant);
        }
    }

    public function test_a_matching_invoice_id_marks_the_instalment_paid(): void
    {
        $this->actingAsRole('director');

        $this->runJobAsFreshWorker(new ProviderWebhookEvent('INV-1', 'org-1'));

        $this->assertSame(InstalmentStatus::Paid, $this->instalment->fresh()->status);
        $this->assertSame(MatterStatus::Active, $this->matter->fresh()->status);
        $this->assertSame(0, AccountingReconciliationIssue::count());
    }

    public function test_an_unmatched_invoice_id_with_no_resolvable_tenant_creates_an_unattributed_issue(): void
    {
        $this->runJobAsFreshWorker(new ProviderWebhookEvent('INV-DOES-NOT-EXIST', 'org-unknown'));

        $issue = AccountingReconciliationIssue::allTenants()->sole();
        $this->assertNull($issue->tenant_id);
        $this->assertSame(ReconciliationIssueReason::WebhookUnmatched, $issue->reason);
        $this->assertSame('INV-DOES-NOT-EXIST', $issue->external_invoice_id);
    }

    public function test_an_unmatched_invoice_id_with_a_resolvable_org_id_attributes_the_issue_to_that_tenant(): void
    {
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'external_org_id' => 'org-known']);

        $this->runJobAsFreshWorker(new ProviderWebhookEvent('INV-DOES-NOT-EXIST', 'org-known'));

        $issue = AccountingReconciliationIssue::sole();
        $this->assertSame($this->tenant->id, $issue->tenant_id);
    }

    public function test_an_already_paid_instalment_is_left_alone(): void
    {
        $paidAt = now()->subDay();
        $this->instalment->update(['status' => InstalmentStatus::Paid, 'paid_at' => $paidAt]);

        $this->runJobAsFreshWorker(new ProviderWebhookEvent('INV-1', 'org-1'));

        $this->assertSame($paidAt->timestamp, $this->instalment->fresh()->paid_at->timestamp);
        $this->assertSame(MatterStatus::Suspended, $this->matter->fresh()->status);
    }
}
