<?php

namespace App\Support\Backups\GoogleDrive;

use App\Enums\BackupDestinationProvider;
use App\Models\BackupDestinationConnection;
use App\Support\Backups\BackupDestinationProviderContract;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Delegated (per-firm) Google OAuth — a separate Google Cloud OAuth client
 * from anything else in this app (there is no existing Google integration).
 * Needs its own OAuth consent screen + client: type "Web application",
 * scope https://www.googleapis.com/auth/drive, redirect URI {APP_URL}
 * /integrations/backups/google_drive/callback. See .env.example.
 *
 * The authorize-URL and token-exchange calls are hand-rolled against
 * Google's plain OAuth2 endpoints via Laravel's Http facade — deliberately
 * not google/apiclient's own Google\Client::fetchAccessTokenWithAuthCode(),
 * which uses its own internal Guzzle client below the Http facade and so
 * can't be faked in tests. Same reasoning SharePointBackupProvider already
 * applies to its Microsoft token exchange. google/apiclient *is* used for
 * the actual Drive API calls (searchDestinations/selectDestination/
 * uploadFile) — those are the same "official SDK, untestable without real
 * credentials" boundary uploadFile() already sits at for SharePoint.
 *
 * Uploads to a firm-chosen Google Shared Drive, not the connecting
 * individual's own My Drive — same "firm-owned, not personal" requirement
 * SharePoint's site picker satisfies. A shared drive's own id doubles as
 * its root folder id in the Drive API, so — unlike SharePoint, where a
 * site's *document library drive* has to be resolved separately from the
 * site id — selectDestination() here needs no extra lookup.
 *
 * access_type=offline + prompt=consent on the authorize URL: Google only
 * issues a refresh_token on a user's *first* consent unless prompt=consent
 * forces the consent screen (and a fresh refresh_token) every time — without
 * this, a firm reconnecting after a disconnect would silently get an
 * access-only connection with no way to refresh it.
 */
class GoogleDriveBackupProvider implements BackupDestinationProviderContract
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPES = 'https://www.googleapis.com/auth/drive';

    private const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    public function key(): BackupDestinationProvider
    {
        return BackupDestinationProvider::GoogleDrive;
    }

    public function getAuthorizationUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.google_backup.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
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
        // always lands in the "connected, choose a shared drive" state;
        // selectDestination() is the only place those get set.
        $connection->forceFill([
            'provider' => BackupDestinationProvider::GoogleDrive,
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

        if (blank($connection->refresh_token)) {
            throw new RuntimeException('This Google Drive connection has no refresh token — it must be reconnected.');
        }

        $token = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);

        $connection->forceFill([
            'access_token' => $token['access_token'],
            // Google's refresh grant never returns a new refresh_token —
            // the original one keeps working indefinitely (until revoked).
            'token_expires_at' => Carbon::now()->addSeconds((int) $token['expires_in']),
        ])->save();
    }

    /**
     * Real Shared Drives matching $query, for the searchable picker — never
     * a free-typed name, and never the connecting individual's own My Drive.
     */
    public function searchDestinations(BackupDestinationConnection $connection, string $query): array
    {
        $this->ensureFreshToken($connection);

        $result = $this->driveService($connection->access_token)->drives->listDrives([
            'q' => "name contains '".addslashes($query)."'",
            'pageSize' => 20,
        ]);

        return collect($result->getDrives())
            ->map(fn (Drive $drive): array => [
                'id' => $drive->getId(),
                'name' => $drive->getName(),
                'url' => "https://drive.google.com/drive/folders/{$drive->getId()}",
            ])
            ->all();
    }

    /**
     * A shared drive's own id already is its root folder id in the Drive
     * API, so — unlike SharePoint's site → document-library-drive lookup —
     * there's nothing further to resolve here.
     */
    public function selectDestination(BackupDestinationConnection $connection, string $id, string $name): void
    {
        $connection->forceFill([
            'site_id' => $id,
            'site_name' => $name,
            'drive_id' => $id,
        ])->save();
    }

    public function uploadFile(BackupDestinationConnection $connection, string $path, string $content): void
    {
        $this->ensureFreshToken($connection);

        if (blank($connection->drive_id)) {
            throw new RuntimeException('This Google Drive connection has no shared drive selected yet.');
        }

        $service = $this->driveService($connection->access_token);

        $segments = explode('/', trim($path, '/'));
        $filename = array_pop($segments);

        $parentId = $connection->drive_id;

        foreach ($segments as $folderName) {
            $parentId = $this->resolveOrCreateFolder($service, $connection->drive_id, $parentId, $folderName);
        }

        $service->files->create(
            new DriveFile(['name' => $filename, 'parents' => [$parentId]]),
            [
                'data' => $content,
                'mimeType' => 'application/zip',
                'uploadType' => 'multipart',
                'supportsAllDrives' => true,
                'fields' => 'id',
            ],
        );
    }

    private function resolveOrCreateFolder(GoogleDrive $service, string $driveId, string $parentId, string $name): string
    {
        $query = sprintf(
            "name = '%s' and mimeType = '%s' and '%s' in parents and trashed = false",
            addslashes($name),
            self::FOLDER_MIME_TYPE,
            $parentId,
        );

        $existing = $service->files->listFiles([
            'q' => $query,
            'corpora' => 'drive',
            'driveId' => $driveId,
            'includeItemsFromAllDrives' => true,
            'supportsAllDrives' => true,
            'fields' => 'files(id, name)',
        ])->getFiles()[0] ?? null;

        if ($existing) {
            return $existing->getId();
        }

        return $service->files->create(
            new DriveFile(['name' => $name, 'mimeType' => self::FOLDER_MIME_TYPE, 'parents' => [$parentId]]),
            ['supportsAllDrives' => true, 'fields' => 'id'],
        )->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestToken(array $params): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google_backup.client_id'),
            'client_secret' => config('services.google_backup.client_secret'),
            ...$params,
        ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException('Google did not return a usable access token: '.$response->body());
        }

        return $response->json();
    }

    private function driveService(string $accessToken): GoogleDrive
    {
        $client = new GoogleClient;
        $client->setAccessToken($accessToken);

        return new GoogleDrive($client);
    }

    private function redirectUri(): string
    {
        return route('integrations.backups.callback', ['provider' => BackupDestinationProvider::GoogleDrive->value]);
    }
}
