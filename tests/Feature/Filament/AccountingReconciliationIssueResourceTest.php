<?php

namespace Tests\Feature\Filament;

use App\Enums\AccountingProviderKey;
use App\Enums\ReconciliationIssueReason;
use App\Filament\Admin\Resources\AccountingReconciliationIssues\AccountingReconciliationIssueResource;
use App\Filament\Admin\Resources\AccountingReconciliationIssues\Pages\ListAccountingReconciliationIssues;
use App\Models\AccountingReconciliationIssue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class AccountingReconciliationIssueResourceTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpTenant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant, isQuiet: true);
    }

    private function makeIssue(): AccountingReconciliationIssue
    {
        return AccountingReconciliationIssue::create([
            'tenant_id' => $this->tenant->id,
            'provider' => AccountingProviderKey::Xero,
            'external_invoice_id' => 'INV-1',
            'reason' => ReconciliationIssueReason::WebhookUnmatched,
            'needs_review' => true,
            'payload' => ['note' => 'test'],
        ]);
    }

    public function test_view_finance_gates_access(): void
    {
        $this->actingAsRole('accounts');
        $this->assertTrue(AccountingReconciliationIssueResource::canAccess());

        $this->actingAsRole('solicitor');
        $this->assertFalse(AccountingReconciliationIssueResource::canAccess());
    }

    public function test_the_pending_tab_shows_only_unreviewed_issues(): void
    {
        $director = $this->actingAsRole('director');
        $issue = $this->makeIssue();
        $issue->markReviewed($director);
        $pending = $this->makeIssue();

        Livewire::test(ListAccountingReconciliationIssues::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$issue]);
    }

    public function test_mark_reviewed_stamps_who_and_when(): void
    {
        $director = $this->actingAsRole('director');
        $issue = $this->makeIssue();

        Livewire::test(ListAccountingReconciliationIssues::class)
            ->callTableAction('markReviewed', $issue);

        $fresh = $issue->fresh();
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame($director->id, $fresh->reviewed_by);
    }

    public function test_creating_an_issue_notifies_directors(): void
    {
        $director = $this->actingAsRole('director');

        $this->makeIssue();

        Notification::assertSentTo($director, \App\Notifications\AccountingReconciliationIssueNotification::class);
    }
}
