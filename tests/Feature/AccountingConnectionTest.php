<?php

namespace Tests\Feature;

use App\Enums\AccountingProviderKey;
use App\Models\AccountingConnection;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class AccountingConnectionTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_connect_manual_creates_a_real_row_with_no_tokens(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $connection = AccountingConnection::connectManual($this->tenant, $director);

        $this->assertSame(AccountingProviderKey::Manual, $connection->provider);
        $this->assertFalse($connection->isRealProvider());
        $this->assertNull($connection->access_token);
        $this->assertNotNull($connection->connected_at);
        $this->assertSame($director->id, $connection->connected_by);
    }

    public function test_connect_manual_is_idempotent_per_tenant(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        AccountingConnection::connectManual($this->tenant, $director);
        AccountingConnection::connectManual($this->tenant, $director);

        $this->assertSame(1, AccountingConnection::allTenants()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_for_tenant_finds_the_right_tenants_connection_regardless_of_ambient_tenant(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        AccountingConnection::connectManual($this->tenant, $director);

        $otherTenant = Tenant::create(['name' => 'Other Firm', 'slug' => 'other-accounting-firm', 'reference_prefix' => 'OAF']);
        $this->actingAsRole('director', $otherTenant);

        $found = AccountingConnection::forTenant($this->tenant);

        $this->assertNotNull($found);
        $this->assertSame($this->tenant->id, $found->tenant_id);
    }

    public function test_for_external_org_id_finds_the_matching_connection(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['provider' => AccountingProviderKey::Xero, 'external_org_id' => 'xero-org-123']);

        $found = AccountingConnection::forExternalOrgId(AccountingProviderKey::Xero, 'xero-org-123');

        $this->assertNotNull($found);
        $this->assertSame($this->tenant->id, $found->tenant_id);
    }

    public function test_for_external_org_id_returns_null_for_no_match(): void
    {
        $this->setUpTenant();

        $this->assertNull(AccountingConnection::forExternalOrgId(AccountingProviderKey::Xero, 'does-not-exist'));
    }

    public function test_token_needs_refresh_within_five_minutes_of_expiry(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);

        $connection->token_expires_at = now()->addMinutes(3);
        $this->assertTrue($connection->tokenNeedsRefresh());

        $connection->token_expires_at = now()->addMinutes(30);
        $this->assertFalse($connection->tokenNeedsRefresh());

        $connection->token_expires_at = null;
        $this->assertFalse($connection->tokenNeedsRefresh());
    }

    public function test_access_and_refresh_tokens_are_encrypted_at_rest(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        $connection = AccountingConnection::connectManual($this->tenant, $director);
        $connection->update(['access_token' => 'plaintext-access-token', 'refresh_token' => 'plaintext-refresh-token']);

        $raw = DB::table('accounting_connections')->where('id', $connection->id)->first();

        $this->assertStringNotContainsString('plaintext-access-token', $raw->access_token);
        $this->assertStringNotContainsString('plaintext-refresh-token', $raw->refresh_token);
        $this->assertSame('plaintext-access-token', $connection->fresh()->access_token);
    }
}
