<?php

namespace Tests\Feature\Filament;

use App\Enums\AccountingProviderKey;
use App\Enums\ClientSource;
use App\Enums\InstalmentStatus;
use App\Enums\MatterStatus;
use App\Filament\Admin\Resources\PaymentPlans\Pages\EditPaymentPlan;
use App\Filament\Admin\Resources\PaymentPlans\RelationManagers\InstalmentsRelationManager;
use App\Models\AccountingConnection;
use App\Models\AccountingReconciliationIssue;
use App\Models\Client;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\PaymentPlan;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 20 — "Send to Xero" (gated on an unlocked plan and a real
 * connection), "Mark Paid" (the manual-parity fix, always available
 * regardless of provider), and "Relink invoice" (director-only correction).
 */
class InstalmentsRelationManagerAccountingTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private PaymentPlan $plan;

    private Instalment $instalment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();

        $client = Client::create([
            'first_name' => 'RM', 'last_name' => 'Client', 'email' => 'rm@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Motoring', 'status' => MatterStatus::Active]);
        $this->plan = PaymentPlan::create(['matter_id' => $matter->id, 'total_amount' => 500, 'deposit_amount' => 0, 'notes' => '', 'locked' => false]);
        $this->instalment = Instalment::create([
            'payment_plan_id' => $this->plan->id,
            'amount' => 500,
            'due_date' => today()->addDays(30)->toDateString(),
            'status' => InstalmentStatus::Pending,
        ]);
    }

    private function mountRelationManager()
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        return Livewire::test(InstalmentsRelationManager::class, [
            'ownerRecord' => $this->plan,
            'pageClass' => EditPaymentPlan::class,
            'lazy' => false,
        ]);
    }

    public function test_send_to_accounting_is_hidden_with_no_real_connection(): void
    {
        $this->actingAsRole('director');

        $this->mountRelationManager()->assertTableActionHidden('sendToAccounting', $this->instalment);
    }

    public function test_send_to_accounting_is_hidden_when_the_plan_is_locked(): void
    {
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'account_code' => '200']);
        $this->plan->update(['locked' => true]);

        $this->mountRelationManager()->assertTableActionHidden('sendToAccounting', $this->instalment);
    }

    public function test_send_to_accounting_is_hidden_once_already_sent(): void
    {
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'account_code' => '200']);
        $this->instalment->markSentToProvider('INV-1');

        $this->mountRelationManager()->assertTableActionHidden('sendToAccounting', $this->instalment);
    }

    public function test_send_to_accounting_is_visible_when_unlocked_and_connected(): void
    {
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'account_code' => '200']);

        $this->mountRelationManager()->assertTableActionVisible('sendToAccounting', $this->instalment);
    }

    public function test_mark_paid_is_always_available_regardless_of_provider(): void
    {
        $this->actingAsRole('director');

        $this->mountRelationManager()
            ->callTableAction('markPaid', $this->instalment)
            ->assertHasNoTableActionErrors();

        $this->assertSame(InstalmentStatus::Paid, $this->instalment->fresh()->status);
    }

    public function test_mark_paid_is_hidden_once_already_paid(): void
    {
        $this->actingAsRole('director');
        $this->instalment->update(['status' => InstalmentStatus::Paid, 'paid_at' => now()]);

        $this->mountRelationManager()->assertTableActionHidden('markPaid', $this->instalment);
    }

    public function test_relink_invoice_requires_manage_integrations(): void
    {
        $this->instalment->markSentToProvider('INV-1');

        $this->actingAsRole('accounts');
        $this->mountRelationManager()->assertTableActionHidden('relinkInvoice', $this->instalment);

        $this->actingAsRole('director');
        $this->mountRelationManager()->assertTableActionVisible('relinkInvoice', $this->instalment);
    }

    public function test_relink_invoice_is_hidden_when_nothing_has_been_sent(): void
    {
        $this->actingAsRole('director');

        $this->mountRelationManager()->assertTableActionHidden('relinkInvoice', $this->instalment);
    }

    public function test_relinking_persists_the_new_id_and_does_not_flag_drift(): void
    {
        $this->actingAsRole('director');
        $this->instalment->markSentToProvider('INV-1');

        $this->mountRelationManager()
            ->callTableAction('relinkInvoice', $this->instalment, data: [
                'new_id' => 'INV-2',
                'reason' => 'Created directly in Xero.',
            ])
            ->assertHasNoTableActionErrors();

        $invoice = $this->instalment->fresh()->invoice;
        $this->assertSame('INV-2', $invoice->provider_invoice_id);
        $this->assertFalse($invoice->out_of_sync_with_provider);
        $this->assertSame(0, AccountingReconciliationIssue::count());
    }
}
