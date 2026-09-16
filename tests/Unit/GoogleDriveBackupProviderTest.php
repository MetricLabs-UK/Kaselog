<?php

namespace Tests\Unit;

use App\Enums\BackupDestinationProvider;
use App\Models\BackupDestinationConnection;
use App\Support\Backups\GoogleDrive\GoogleDriveBackupProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Same testing boundary as SharePointBackupProviderTest: only the parts of
 * this provider that go through Laravel's Http facade are exercised here
 * (getAuthorizationUrl, handleAuthorizationCallback, ensureFreshToken) —
 * searchDestinations()/selectDestination()/uploadFile() all go through
 * google/apiclient's Google\Client, which uses its own internal Guzzle
 * client rather than the Http facade.
 */
class GoogleDriveBackupProviderTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private GoogleDriveBackupProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_backup.client_id' => 'test-client-id', 'services.google_backup.client_secret' => 'test-secret']);
        $this->provider = new GoogleDriveBackupProvider;
    }

    private function connection(): BackupDestinationConnection
    {
        $this->setUpTenant();

        return BackupDestinationConnection::create([
            'tenant_id' => $this->tenant->id,
            'provider' => BackupDestinationProvider::GoogleDrive,
        ]);
    }

    public function test_the_authorization_url_points_at_googles_endpoint_and_forces_the_consent_prompt(): void
    {
        $url = $this->provider->getAuthorizationUrl('opaque-state-value');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=opaque-state-value', $url);
        $this->assertStringContainsString('access_type=offline', urldecode($url));
        $this->assertStringContainsString('prompt=consent', urldecode($url));
        $this->assertStringContainsString('https://www.googleapis.com/auth/drive', urldecode($url));
    }

    public function test_handling_the_callback_exchanges_the_code_and_persists_the_token_pair(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
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
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(RuntimeException::class);

        $this->provider->handleAuthorizationCallback('bad-code', $this->connection());
    }

    public function test_ensure_fresh_token_is_a_no_op_when_the_token_is_not_stale(): void
    {
        Http::fake();

        $connection = $this->connection();
        $connection->forceFill(['access_token' => 'still-valid', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour()])->save();

        $this->provider->ensureFreshToken($connection);

        Http::assertNothingSent();
        $this->assertSame('still-valid', $connection->fresh()->access_token);
    }

    public function test_ensure_fresh_token_refreshes_and_always_keeps_the_original_refresh_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'rotated-access-token',
                'expires_in' => 3600,
                // Google's refresh grant deliberately omits refresh_token.
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

    public function test_ensure_fresh_token_refuses_to_refresh_without_a_refresh_token(): void
    {
        Http::fake();

        $connection = $this->connection();
        $connection->forceFill(['access_token' => 'stale', 'refresh_token' => null, 'token_expires_at' => now()->subMinute()])->save();

        $this->expectException(RuntimeException::class);

        $this->provider->ensureFreshToken($connection);
    }
}
