<?php

namespace Tests\Feature;

use App\Enums\AccountingProviderKey;
use App\Enums\ClientSource;
use App\Enums\InstalmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MatterStatus;
use App\Enums\ReconciliationIssueReason;
use App\Models\AccountingConnection;
use App\Models\AccountingReconciliationIssue;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\PaymentPlan;
use App\Notifications\MatterReactivatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 20/6 — the manual-parity fix (Instalment::markPaid() is now the
 * one place "an instalment got paid" happens, used by both the accounting
 * webhook and a manual action) and the drift-detection requirement (an edit
 * after an invoice was sent must never be silent). Since Section 6, the
 * provider_invoice_id/out_of_sync_with_provider fields this test exercises
 * live on the unified Invoice model, reached via Instalment::invoice — see
 * that model's own docblocks for why.
 */
class InstalmentAccountingSyncTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private Matter $matter;

    private PaymentPlan $plan;

    private Instalment $instalment;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpTenant();

        $client = Client::create([
            'first_name' => 'Sync', 'last_name' => 'Client', 'email' => 'sync@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
        ]);

        $this->matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Motoring',
            'status' => MatterStatus::Suspended,
        ]);

        $this->plan = PaymentPlan::create([
            'matter_id' => $this->matter->id,
            'total_amount' => 1000,
            'deposit_amount' => 100,
            'notes' => '',
        ]);

        $this->instalment = Instalment::create([
            'payment_plan_id' => $this->plan->id,
            'amount' => 100,
            'due_date' => today()->subDays(30)->toDateString(),
            'status' => InstalmentStatus::Overdue,
        ]);
    }

    public function test_mark_paid_reactivates_a_suspended_matter_and_logs_a_distinct_event(): void
    {
        $director = $this->actingAsRole('director');

        // ChaseLog.status casts to ChaseLogStatus (sent/failed/cancelled only
        // — no "pending" case exists, per that model's own docblock: chase
        // logs are a historical record, not a schedule of future actions).
        // The cancel-pending-chases step in markPaid() is defensive against a
        // future design that adds one; there is no real row to construct
        // here that would exercise it today.
        $this->instalment->markPaid();

        $this->assertSame(InstalmentStatus::Paid, $this->instalment->fresh()->status);
        $this->assertNotNull($this->instalment->fresh()->paid_at);
        $this->assertSame(MatterStatus::Active, $this->matter->fresh()->status);

        Notification::assertSentTo($director, MatterReactivatedNotification::class);

        $this->assertTrue(Activity::where('event', 'paid')->where('subject_id', $this->instalment->id)->exists());
    }

    public function test_mark_paid_on_an_already_paid_instalment_is_a_no_op(): void
    {
        $this->actingAsRole('director');
        $this->instalment->update(['status' => InstalmentStatus::Paid, 'paid_at' => now()->subDay()]);
        $paidAt = $this->instalment->paid_at;

        $this->instalment->markPaid();

        $this->assertSame($paidAt->timestamp, $this->instalment->fresh()->paid_at->timestamp);
        $this->assertSame(MatterStatus::Suspended, $this->matter->fresh()->status);
    }

    public function test_mark_paid_cascades_to_the_attached_invoice(): void
    {
        $this->actingAsRole('director');
        $this->instalment->markSentToProvider('INV-1');

        $this->instalment->markPaid();

        $this->assertSame(InvoiceStatus::Paid, $this->instalment->fresh()->invoice->status);
        $this->assertFalse($this->instalment->fresh()->invoice->out_of_sync_with_provider);
        $this->assertSame(0, AccountingReconciliationIssue::count());
    }

    public function test_setting_the_provider_invoice_id_for_the_first_time_does_not_flag_drift(): void
    {
        $this->actingAsRole('director');

        $this->instalment->markSentToProvider('INV-1');

        $invoice = $this->instalment->fresh()->invoice;
        $this->assertSame('INV-1', $invoice->provider_invoice_id);
        $this->assertFalse($invoice->out_of_sync_with_provider);
        $this->assertSame(0, AccountingReconciliationIssue::count());
    }

    public function test_editing_an_already_sent_instalment_flags_it_out_of_sync(): void
    {
        $this->actingAsRole('director');
        $this->instalment->markSentToProvider('INV-1');

        $this->instalment->update(['amount' => 250]);

        $fresh = $this->instalment->fresh();
        $this->assertTrue($fresh->invoice->out_of_sync_with_provider);

        $issue = AccountingReconciliationIssue::sole();
        $this->assertSame(ReconciliationIssueReason::InstalmentEditedAfterSend, $issue->reason);
        $this->assertSame($fresh->invoice_id, $issue->invoice_id);
        $this->assertSame($this->tenant->id, $issue->tenant_id);
    }

    public function test_editing_an_unsent_instalment_does_not_flag_anything(): void
    {
        $this->actingAsRole('director');

        $this->instalment->update(['amount' => 250]);

        $this->assertNull($this->instalment->fresh()->invoice);
        $this->assertSame(0, AccountingReconciliationIssue::count());
    }

    public function test_relinking_the_provider_invoice_does_not_flag_drift(): void
    {
        $director = $this->actingAsRole('director');
        $this->instalment->markSentToProvider('INV-1');

        $this->instalment->relinkProvider('INV-2', $director, 'Created directly in Xero by the accountant.');

        $fresh = $this->instalment->fresh()->invoice;
        $this->assertSame('INV-2', $fresh->provider_invoice_id);
        $this->assertFalse($fresh->out_of_sync_with_provider);
        $this->assertSame(0, AccountingReconciliationIssue::count());
        $this->assertTrue(Activity::where('event', 'provider_invoice_relinked')->exists());
    }

    public function test_editing_the_payment_plans_total_amount_flags_every_sent_instalment(): void
    {
        $this->actingAsRole('director');
        $this->instalment->markSentToProvider('INV-1');

        $unsentInstalment = Instalment::create([
            'payment_plan_id' => $this->plan->id,
            'amount' => 900,
            'due_date' => today()->addDays(30)->toDateString(),
            'status' => InstalmentStatus::Pending,
        ]);

        $this->plan->update(['total_amount' => 1500]);

        $this->assertTrue($this->instalment->fresh()->invoice->out_of_sync_with_provider);
        $this->assertNull($unsentInstalment->fresh()->invoice);

        $issue = AccountingReconciliationIssue::sole();
        $this->assertSame(ReconciliationIssueReason::PaymentPlanEditedAfterSend, $issue->reason);
    }

    public function test_editing_an_unrelated_payment_plan_field_does_not_flag_anything(): void
    {
        $this->actingAsRole('director');
        $this->instalment->markSentToProvider('INV-1');

        $this->plan->update(['notes' => 'A routine note update.']);

        $this->assertFalse($this->instalment->fresh()->invoice->out_of_sync_with_provider);
        $this->assertSame(0, AccountingReconciliationIssue::count());
    }

    public function test_marking_a_reconciliation_issue_reviewed_clears_the_instalment_flag(): void
    {
        $director = $this->actingAsRole('director');
        $this->instalment->markSentToProvider('INV-1');
        $this->instalment->update(['amount' => 250]);

        $this->assertTrue($this->instalment->fresh()->invoice->out_of_sync_with_provider);

        AccountingReconciliationIssue::sole()->markReviewed($director);

        $this->assertFalse($this->instalment->fresh()->invoice->out_of_sync_with_provider);
    }

    public function test_flag_out_of_sync_falls_back_to_manual_provider_when_no_connection_exists(): void
    {
        $this->actingAsRole('director');
        $this->instalment->markSentToProvider('INV-1');

        $this->instalment->update(['amount' => 250]);

        $this->assertSame(AccountingProviderKey::Manual, AccountingReconciliationIssue::sole()->provider);
    }

    public function test_flag_out_of_sync_records_the_actual_connected_provider(): void
    {
        $director = $this->actingAsRole('director');
        AccountingConnection::connectManual($this->tenant, $director);
        AccountingConnection::forTenant($this->tenant)->update(['provider' => AccountingProviderKey::Xero, 'external_org_id' => 'org-1']);
        $this->instalment->markSentToProvider('INV-1');

        $this->instalment->update(['amount' => 250]);

        $this->assertSame(AccountingProviderKey::Xero, AccountingReconciliationIssue::sole()->provider);
    }
}
