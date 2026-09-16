<?php

namespace Tests\Feature;

use App\Enums\AccountingProviderKey;
use App\Enums\ClientSource;
use App\Enums\InvoiceStatus;
use App\Enums\MatterStatus;
use App\Enums\ReconciliationIssueReason;
use App\Filament\Admin\Resources\TimeEntries\TimeEntryResource;
use App\Jobs\ProcessAccountingPaymentWebhook;
use App\Models\AccountingConnection;
use App\Models\AccountingReconciliationIssue;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Accounting\ProviderWebhookEvent;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 6 — TimeEntry invoicing, unified under the same Invoice model
 * PaymentPlan/Instalment billing uses (see the scoping notes for why).
 * Covers: bundling billable time entries into a draft Invoice, the locking
 * this triggers, and the same drift-detection pattern Instalment already
 * has, now generalized onto Invoice.
 */
class InvoiceTimeEntryBundlingTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private Matter $matter;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpTenant();

        $client = Client::create([
            'first_name' => 'Bundle', 'last_name' => 'Client', 'email' => 'bundle@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
        ]);

        $this->matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Litigation',
            'status' => MatterStatus::Active,
        ]);

        $this->user = $this->actingAsRole('solicitor');
    }

    private function makeTimeEntry(array $overrides = []): TimeEntry
    {
        return TimeEntry::create(array_merge([
            'matter_id' => $this->matter->id,
            'duration_seconds' => 3600,
            'description' => 'Drafting letter',
            'billable' => true,
            'billing_rate' => 100,
        ], $overrides));
    }

    public function test_bundling_creates_a_draft_invoice_and_locks_the_entries(): void
    {
        $director = $this->actingAsRole('director');
        $entryOne = $this->makeTimeEntry();
        $entryTwo = $this->makeTimeEntry(['duration_seconds' => 1800]);

        $invoice = Invoice::createDraftForTimeEntries(
            TimeEntry::query()->whereIn('id', [$entryOne->id, $entryTwo->id])->get(),
            $director,
        );

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($this->matter->id, $invoice->matter_id);
        $this->assertSame($this->matter->client_id, $invoice->client_id);
        $this->assertSame('150.00', (string) $invoice->total_amount);

        $this->assertTrue($entryOne->fresh()->locked);
        $this->assertSame($invoice->id, $entryOne->fresh()->invoice_id);
        $this->assertTrue($entryTwo->fresh()->locked);
    }

    public function test_bundling_rejects_entries_from_different_matters(): void
    {
        $director = $this->actingAsRole('director');
        $entryOne = $this->makeTimeEntry();

        $otherClient = Client::create([
            'first_name' => 'Other', 'last_name' => 'Client', 'email' => 'other@example.com',
            'phone' => '2', 'source' => ClientSource::Phone,
        ]);
        $otherMatter = Matter::create(['client_id' => $otherClient->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);
        $entryTwo = $this->makeTimeEntry(['matter_id' => $otherMatter->id, 'client_id' => $otherClient->id]);

        $this->expectException(InvalidArgumentException::class);

        Invoice::createDraftForTimeEntries(
            TimeEntry::query()->whereIn('id', [$entryOne->id, $entryTwo->id])->get(),
            $director,
        );
    }

    public function test_a_locked_bundled_entry_cannot_be_edited_by_a_non_privileged_user_even_if_locked_is_flipped_false(): void
    {
        $director = $this->actingAsRole('director');
        $entry = $this->makeTimeEntry(['user_id' => $this->user->id]);
        Invoice::createDraftForTimeEntries(TimeEntry::query()->whereKey($entry->id)->get(), $director);

        // A director could flip `locked` back off directly; invoice_id alone
        // must still gate the edit — see TimeEntryResource::canEdit()'s own
        // comment for why.
        $entry->forceFill(['locked' => false])->saveQuietly();

        $this->actingAsRole('solicitor');
        $this->assertFalse(TimeEntryResource::canEdit($entry->fresh()));
    }

    public function test_editing_a_bundled_entry_after_it_has_been_sent_flags_the_invoice_out_of_sync(): void
    {
        $director = $this->actingAsRole('director');
        $entry = $this->makeTimeEntry();
        $invoice = Invoice::createDraftForTimeEntries(TimeEntry::query()->whereKey($entry->id)->get(), $director);
        $invoice->markSentToProvider('INV-TE-1');

        // Bundling attached invoice_id via a bulk query-builder update, which
        // this in-memory $entry object never saw — reload before editing so
        // its own getOriginal('invoice_id') reflects the real, now-attached
        // state (exactly what the updating hook needs to see).
        $entry->fresh()->forceFill(['billing_rate' => 200])->save();

        $this->assertTrue($invoice->fresh()->out_of_sync_with_provider);

        $issue = AccountingReconciliationIssue::sole();
        $this->assertSame(ReconciliationIssueReason::TimeEntryEditedAfterSend, $issue->reason);
        $this->assertSame($invoice->id, $issue->invoice_id);
    }

    public function test_editing_an_unbundled_entry_does_not_flag_anything(): void
    {
        $entry = $this->makeTimeEntry();

        $entry->update(['description' => 'Updated description']);

        $this->assertSame(0, AccountingReconciliationIssue::count());
    }

    public function test_invoice_line_items_are_one_per_time_entry(): void
    {
        $director = $this->actingAsRole('director');
        $entryOne = $this->makeTimeEntry(['description' => 'Call with client']);
        $entryTwo = $this->makeTimeEntry(['description' => 'Drafting particulars', 'duration_seconds' => 7200]);

        $invoice = Invoice::createDraftForTimeEntries(
            TimeEntry::query()->whereIn('id', [$entryOne->id, $entryTwo->id])->get(),
            $director,
        );

        $lines = $invoice->lineItemsData();
        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(100.0, $lines[0]['unitAmount'], 0.001);
        $this->assertEqualsWithDelta(200.0, $lines[1]['unitAmount'], 0.001);
    }

    public function test_accounting_webhook_marks_a_time_entry_sourced_invoice_paid(): void
    {
        $director = $this->actingAsRole('director');
        $entry = $this->makeTimeEntry();
        $invoice = Invoice::createDraftForTimeEntries(TimeEntry::query()->whereKey($entry->id)->get(), $director);
        $invoice->markSentToProvider('INV-TE-2');

        CurrentTenant::clear();

        try {
            (new ProcessAccountingPaymentWebhook(AccountingProviderKey::Xero, new ProviderWebhookEvent('INV-TE-2', 'org-1')))->handle();
        } finally {
            CurrentTenant::set($this->tenant);
        }

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
        $this->assertSame(0, AccountingReconciliationIssue::count());
    }

    public function test_mark_paid_records_the_connected_provider_on_send(): void
    {
        $director = $this->actingAsRole('director');
        AccountingConnection::connectManual($this->tenant, $director);
        AccountingConnection::forTenant($this->tenant)->update(['provider' => AccountingProviderKey::Xero, 'external_org_id' => 'org-1']);

        $entry = $this->makeTimeEntry();
        $invoice = Invoice::createDraftForTimeEntries(TimeEntry::query()->whereKey($entry->id)->get(), $director);
        $invoice->markSentToProvider('INV-TE-3', 'INV-0003');

        $fresh = $invoice->fresh();
        $this->assertSame(AccountingProviderKey::Xero, $fresh->provider);
        $this->assertSame('INV-0003', $fresh->provider_invoice_number);
        $this->assertSame(InvoiceStatus::Sent, $fresh->status);
    }
}
