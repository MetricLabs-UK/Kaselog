<?php

namespace Tests\Feature\Filament;

use App\Enums\AccountingProviderKey;
use App\Enums\ClientSource;
use App\Enums\InvoiceStatus;
use App\Enums\MatterStatus;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Models\AccountingConnection;
use App\Models\AccountingReconciliationIssue;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TimeEntry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 6 — the unified invoice list, and the second half of the
 * time-entry "bundle then review then send" flow (bundling itself is
 * UnbilledTimeTest's job).
 */
class InvoiceResourceTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant, isQuiet: true);

        $client = Client::create([
            'first_name' => 'Invoice', 'last_name' => 'Client', 'email' => 'invoice@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
        ]);
        $this->matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Litigation', 'status' => MatterStatus::Active]);
    }

    private function makeDraftInvoice(): Invoice
    {
        $director = $this->actingAsRole('director');

        $entry = TimeEntry::create([
            'matter_id' => $this->matter->id,
            'duration_seconds' => 3600,
            'description' => 'Work done',
            'billable' => true,
            'billing_rate' => 100,
        ]);

        return Invoice::createDraftForTimeEntries(TimeEntry::query()->whereKey($entry->id)->get(), $director);
    }

    public function test_only_manage_invoices_holders_can_access_the_resource(): void
    {
        $this->actingAsRole('accounts');
        $this->assertTrue(InvoiceResource::canAccess());

        $this->actingAsRole('solicitor');
        $this->assertFalse(InvoiceResource::canAccess());
    }

    public function test_send_to_accounting_is_hidden_with_no_real_connection(): void
    {
        $invoice = $this->makeDraftInvoice();
        $this->actingAsRole('director');

        Livewire::test(ListInvoices::class)->assertTableActionHidden('sendToAccounting', $invoice);
    }

    public function test_send_to_accounting_is_visible_when_connected_and_hidden_once_sent(): void
    {
        $invoice = $this->makeDraftInvoice();
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'account_code' => '200']);

        Livewire::test(ListInvoices::class)->assertTableActionVisible('sendToAccounting', $invoice);

        $invoice->markSentToProvider('INV-X');

        Livewire::test(ListInvoices::class)->assertTableActionHidden('sendToAccounting', $invoice);
    }

    public function test_mark_paid_updates_status(): void
    {
        $invoice = $this->makeDraftInvoice();
        $invoice->markSentToProvider('INV-X');
        $this->actingAsRole('director');

        Livewire::test(ListInvoices::class)
            ->callTableAction('markPaid', $invoice)
            ->assertHasNoTableActionErrors();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_relink_invoice_requires_manage_integrations(): void
    {
        $invoice = $this->makeDraftInvoice();
        $invoice->markSentToProvider('INV-X');

        $this->actingAsRole('accounts');
        Livewire::test(ListInvoices::class)->assertTableActionHidden('relinkInvoice', $invoice);

        $this->actingAsRole('director');
        Livewire::test(ListInvoices::class)
            ->callTableAction('relinkInvoice', $invoice, data: ['new_id' => 'INV-Y', 'reason' => 'Corrected manually.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('INV-Y', $invoice->fresh()->provider_invoice_id);
        $this->assertSame(0, AccountingReconciliationIssue::count());
    }
}
