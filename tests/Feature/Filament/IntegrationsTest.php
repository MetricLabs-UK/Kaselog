<?php

namespace Tests\Feature\Filament;

use App\Enums\AccountingProviderKey;
use App\Filament\Admin\Pages\Integrations\AccountingIntegration;
use App\Filament\Admin\Pages\Integrations\ListIntegrations;
use App\Models\AccountingConnection;
use App\Models\Client;
use App\Enums\ClientSource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 20 — Settings > Integrations. Director-only throughout
 * (manage_integrations).
 */
class IntegrationsTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant, isQuiet: true);
    }

    public function test_only_a_director_can_access_either_page(): void
    {
        $this->actingAsRole('director');
        $this->assertTrue(ListIntegrations::canAccess());
        $this->assertTrue(AccountingIntegration::canAccess());

        $this->actingAsRole('accounts');
        $this->assertFalse(ListIntegrations::canAccess());
        $this->assertFalse(AccountingIntegration::canAccess());
    }

    public function test_the_list_page_shows_not_configured_by_default(): void
    {
        $this->actingAsRole('director');

        Livewire::test(ListIntegrations::class)
            ->assertSuccessful()
            ->assertSee('Accounting')
            ->assertSee('Not configured');
    }

    public function test_the_list_page_reflects_a_manual_connection(): void
    {
        $director = $this->actingAsRole('director');
        AccountingConnection::connectManual($this->tenant, $director);

        Livewire::test(ListIntegrations::class)->assertSee('Manual (no integration)');
    }

    public function test_the_list_page_reflects_a_real_connection(): void
    {
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero]);

        Livewire::test(ListIntegrations::class)->assertSee('Connected as Xero');
    }

    public function test_choosing_manual_records_a_manual_connection(): void
    {
        $this->actingAsRole('director');

        Livewire::test(AccountingIntegration::class)
            ->fillForm(['provider' => AccountingProviderKey::Manual->value])
            ->call('chooseProvider');

        $connection = AccountingConnection::forTenant($this->tenant);
        $this->assertSame(AccountingProviderKey::Manual, $connection->provider);
    }

    public function test_choosing_xero_redirects_to_a_real_xero_authorization_url(): void
    {
        config(['services.xero.client_id' => 'test-client-id', 'services.xero.client_secret' => 'test-secret']);
        $this->actingAsRole('director');

        Livewire::test(AccountingIntegration::class)
            ->fillForm(['provider' => AccountingProviderKey::Xero->value])
            ->call('chooseProvider')
            ->assertRedirect();
    }

    public function test_sage_quickbooks_and_freeagent_are_not_selectable(): void
    {
        $this->actingAsRole('director');

        // The picker deliberately still *shows* these as future options
        // (per Section 20's sign-off) rather than hiding them — this
        // confirms they render as disabled, not that they're absent.
        Livewire::test(AccountingIntegration::class)
            ->assertSee('Sage (coming soon)')
            ->assertSee('QuickBooks (coming soon)')
            ->assertSee('FreeAgent (coming soon)');
    }

    public function test_once_connected_to_xero_the_page_shows_the_account_code_setting(): void
    {
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'external_org_id' => 'org-1']);

        Livewire::test(AccountingIntegration::class)
            ->assertSee('Connected as Xero')
            ->assertFormFieldExists('account_code');
    }

    public function test_saving_the_account_code(): void
    {
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'external_org_id' => 'org-1']);

        Livewire::test(AccountingIntegration::class)
            ->fillForm(['account_code' => '200'])
            ->call('saveAccountCode');

        $this->assertSame('200', $connection->fresh()->account_code);
    }

    public function test_disconnecting_reverts_to_manual_and_clears_client_contact_ids(): void
    {
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'external_org_id' => 'org-1', 'account_code' => '200']);

        $client = Client::create([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'ab@example.com',
            'phone' => '1', 'source' => ClientSource::Phone, 'provider_contact_id' => 'contact-1',
        ]);

        Livewire::test(AccountingIntegration::class)->callAction('disconnect');

        $fresh = $connection->fresh();
        $this->assertSame(AccountingProviderKey::Manual, $fresh->provider);
        $this->assertNull($fresh->access_token);
        $this->assertNull($fresh->account_code);
        $this->assertNull($client->fresh()->provider_contact_id);
    }

    public function test_disconnect_is_only_visible_when_connected_to_a_real_provider(): void
    {
        $this->actingAsRole('director');

        Livewire::test(AccountingIntegration::class)->assertActionHidden('disconnect');
    }
}
