<?php

namespace App\Support\Backups\SharePoint;

use App\Enums\BackupDestinationProvider;
use App\Models\BackupDestinationConnection;
use App\Support\Backups\BackupDestinationProviderContract;
use Carbon\Carbon;
use GWSN\Microsoft\ApiConnector;
use GWSN\Microsoft\Drive\FileService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Delegated (per-firm) Microsoft Graph auth — a materially different auth
 * model from Section 12's Kase-internal 'sharepoint' disk, which uses one
 * fixed app-only (client_credentials) Azure AD app. This needs its own,
 * second Azure AD app registration: multi-tenant, delegated
 * Sites.ReadWrite.All + offline_access, redirect URI {APP_URL}/integrations
 * /backups/sharepoint/callback. See .env.example for the full checklist.
 *
 * gwsn/flysystem-sharepoint-adapter's SharepointConnector can't be reused
 * here — its constructor is hard-wired to request an app-only token itself
 * (GWSN\Microsoft\Authentication\AuthenticationService::getAccessToken()
 * always sends grant_type=client_credentials, with no way to inject a
 * pre-obtained token). What *is* reusable is the lower-level Graph SDK it
 * wraps (gwsn/sharepoint-sdk): FileService/ApiConnector take a raw access
 * token directly in their own constructors, completely decoupled from
 * AuthenticationService — so the actual Graph upload calls are the same
 * well-tested SDK Section 12 already depends on, just fed a delegated
 * token this class obtains itself instead.
 *
 * Uploads to a firm-chosen SharePoint site's default document library, not
 * the connecting individual's own OneDrive — a shared site is what actually
 * belongs to the firm rather than one person. Connecting is two steps:
 * OAuth first (persists tokens, no site yet), then the firm searches Graph's
 * real site list (searchDestinations(), via /v1.0/sites?search=) and picks
 * one (selectDestination(), which resolves and stores that site's drive
 * id) — a
 * searchable picker over a free-typed name, so there's no way to get the
 * site wrong, while it's still explicitly the firm's shared site rather than
 * an individual's storage.
 *
 * writeFile() is a simple PUT upload (Microsoft Graph's "simple upload",
 * ~250MB limit) — fine for a single client's document bundle, the only
 * thing ever pushed to a cloud destination (whole-firm stays zip-download-
 * only). A resumable upload session would be needed to lift that limit if
 * it ever becomes a real constraint.
 */
class SharePointBackupProvider implements BackupDestinationProviderContract
{
    private const AUTHORIZE_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';

    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const SCOPES = 'openid offline_access Sites.ReadWrite.All';

    public function key(): BackupDestinationProvider
    {
        return BackupDestinationProvider::SharePoint;
    }

    public function getAuthorizationUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.microsoft_backup.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
        ]);
    }

    public function handleAuthorizationCallback(string $code, BackupDestinationConnection $connection): void
    {
        $token = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);

        // Deliberately no site_id/drive_id here — a fresh OAuth connection
        // always lands in the "connected, choose a site" state;
        // selectDestination() is the only place those get set.
        $connection->forceFill([
            'provider' => BackupDestinationProvider::SharePoint,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => Carbon::now()->addSeconds((int) $token['expires_in']),
            'connected_at' => now(),
            'disconnected_at' => null,
        ])->save();
    }

    public function ensureFreshToken(BackupDestinationConnection $connection): void
    {
        if (! $connection->tokenNeedsRefresh()) {
            return;
        }

        $token = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);

        $connection->forceFill([
            'access_token' => $token['access_token'],
            // Microsoft doesn't always rotate the refresh token on every
            // refresh — keep the existing one when a new one isn't sent.
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => Carbon::now()->addSeconds((int) $token['expires_in']),
        ])->save();
    }

    /**
     * Real Graph sites matching $query, for the searchable site picker —
     * never a free-typed name a firm has to get exactly right.
     */
    public function searchDestinations(BackupDestinationConnection $connection, string $query): array
    {
        $this->ensureFreshToken($connection);

        $connector = new ApiConnector($connection->access_token);
        $response = $connector->request('GET', '/v1.0/sites', ['search' => $query]);

        return collect($response['value'] ?? [])
            ->map(fn (array $site): array => [
                'id' => $site['id'],
                'name' => $site['displayName'] ?? $site['name'] ?? $site['webUrl'],
                'url' => $site['webUrl'] ?? '',
            ])
            ->all();
    }

    /**
     * Resolves the chosen site's default document library drive and stores
     * it — this is what actually finishes connecting a SharePoint
     * destination; a connection with tokens but no drive_id is still
     * awaiting site selection (see BackupDestinationConnection::
     * hasSiteSelected()).
     */
    public function selectDestination(BackupDestinationConnection $connection, string $id, string $name): void
    {
        $this->ensureFreshToken($connection);

        $connector = new ApiConnector($connection->access_token);
        $drive = $connector->request('GET', "/v1.0/sites/{$id}/drive");

        if (blank($drive['id'] ?? null)) {
            throw new RuntimeException("Could not resolve a document library for \"{$name}\".");
        }

        $connection->forceFill([
            'site_id' => $id,
            'site_name' => $name,
            'drive_id' => $drive['id'],
        ])->save();
    }

    public function uploadFile(BackupDestinationConnection $connection, string $path, string $content): void
    {
        $this->ensureFreshToken($connection);

        if (blank($connection->drive_id)) {
            throw new RuntimeException('This SharePoint connection has no site selected yet.');
        }

        (new FileService($connection->access_token, $connection->drive_id))->writeFile($path, $content, 'application/zip');
    }

    /**
     * @return array<string, mixed>
     */
    private function requestToken(array $params): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.microsoft_backup.client_id'),
            'client_secret' => config('services.microsoft_backup.client_secret'),
            'scope' => self::SCOPES,
            ...$params,
        ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException('Microsoft did not return a usable access token: '.$response->body());
        }

        return $response->json();
    }

    private function redirectUri(): string
    {
        return route('integrations.backups.callback', ['provider' => BackupDestinationProvider::SharePoint->value]);
    }
}
