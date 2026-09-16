<?php

namespace Tests\Unit;

use App\Enums\BackupDestinationProvider;
use App\Models\BackupDestinationConnection;
use App\Support\Backups\SharePoint\SharePointBackupProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Only the parts of this provider that go through Laravel's Http facade are
 * exercised here (getAuthorizationUrl, handleAuthorizationCallback,
 * ensureFreshToken) — uploadFile(), searchSites(), and selectSite() all call
 * gwsn/sharepoint-sdk's ApiConnector, which uses its own internal Guzzle
 * client rather than the Http facade, so Http::fake() can't intercept them.
 * That's the same boundary XeroAccountingProvider's own Guzzle-backed API
 * calls (createInvoice, findOrCreateContact) already sit at in this
 * codebase — none of these have automated coverage for their real external
 * call, all wait on real credentials for end-to-end verification.
 */
class SharePointBackupProviderTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private SharePointBackupProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.microsoft_backup.client_id' => 'test-client-id', 'services.microsoft_backup.client_secret' => 'test-secret']);
        $this->provider = new SharePointBackupProvider;
    }

    private function connection(): BackupDestinationConnection
    {
        $this->setUpTenant();

        return BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::SharePoint,
        ]);
    }

    public function test_the_authorization_url_points_at_microsofts_v2_endpoint_with_the_right_params(): void
    {
        $url = $this->provider->getAuthorizationUrl('opaque-state-value');

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=opaque-state-value', $url);
        $this->assertStringContainsString('offline_access', urldecode($url));
        $this->assertStringContainsString('Sites.ReadWrite.All', urldecode($url));
    }

    public function test_handling_the_callback_exchanges_the_code_and_persists_the_token_pair(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'real-access-token',
                'refresh_token' => 'real-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);

        $connection = $this->connection();

        $this->provider->handleAuthorizationCallback('auth-code', $connection);

        $fresh = $connection->fresh();
        $this->assertSame('real-access-token', $fresh->access_token);
        $this->assertSame('real-refresh-token', $fresh->refresh_token);
        $this->assertNotNull($fresh->token_expires_at);
        $this->assertNotNull($fresh->connected_at);

        Http::assertSent(fn ($request) => $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code');
    }

    public function test_a_failed_token_exchange_throws_rather_than_silently_persisting_nothing(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(\RuntimeException::class);

        $this->provider->handleAuthorizationCallback('bad-code', $this->connection());
    }

    public function test_ensure_fresh_token_is_a_no_op_when_the_token_is_not_stale(): void
    {
        Http::fake();

        $connection = $this->connection();
        $connection->forceFill(['access_token' => 'still-valid', 'token_expires_at' => now()->addHour()])->save();

        $this->provider->ensureFreshToken($connection);

        Http::assertNothingSent();
        $this->assertSame('still-valid', $connection->fresh()->access_token);
    }

    public function test_ensure_fresh_token_refreshes_and_keeps_the_old_refresh_token_if_none_is_returned(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'rotated-access-token',
                'expires_in' => 3600,
            ]),
        ]);

        $connection = $this->connection();
        $connection->forceFill([
            'access_token' => 'stale-token',
            'refresh_token' => 'original-refresh-token',
            'token_expires_at' => now()->subMinute(),
        ])->save();

        $this->provider->ensureFreshToken($connection);

        $fresh = $connection->fresh();
        $this->assertSame('rotated-access-token', $fresh->access_token);
        $this->assertSame('original-refresh-token', $fresh->refresh_token);

        Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'original-refresh-token');
    }
}
