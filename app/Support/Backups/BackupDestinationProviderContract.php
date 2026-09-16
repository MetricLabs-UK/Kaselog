<?php

namespace App\Support\Backups;

use App\Enums\BackupDestinationProvider;
use App\Models\BackupDestinationConnection;

/**
 * Sibling to App\Support\Accounting\AccountingProviderContract — same
 * per-tenant delegated OAuth shape (see OAuthState, reused unchanged from
 * Section 5), narrowed to what a backup destination actually needs: no
 * contact/invoice/webhook concepts, just "send this destination my
 * authorization" and "upload this file". SharePointBackupProvider was the
 * first implementation; GoogleDriveBackupProvider (Phase 4) is the second —
 * both need the same "pick a firm-owned shared location, not a personal
 * one" step (searchDestinations()/selectDestination()), promoted onto this
 * interface once a second provider confirmed it's a real shared need rather
 * than a SharePoint-specific one.
 */
interface BackupDestinationProviderContract
{
    public function key(): BackupDestinationProvider;

    /**
     * Where to send the firm's browser to authorize this connection. $state
     * is opaque to the provider — see BackupOAuthController.
     */
    public function getAuthorizationUrl(string $state): string;

    /**
     * Exchanges an authorization code for a token pair and persists it onto
     * $connection — the one place a fresh connection's tokens get written.
     */
    public function handleAuthorizationCallback(string $code, BackupDestinationConnection $connection): void;

    /**
     * Refreshes $connection's access token if it's at or near expiry,
     * persisting the rotated pair immediately. A no-op if the current token
     * is still comfortably valid. Every method below that makes a real API
     * call must call this first.
     */
    public function ensureFreshToken(BackupDestinationConnection $connection): void;

    /**
     * Real, firm-owned shared locations within this destination matching
     * $query — a SharePoint site or a Google Shared Drive, never the
     * connecting individual's own personal storage. Powers the searchable
     * picker a firm sees once connected but before a location is chosen
     * (BackupDestinationConnection::hasSiteSelected()) — a firm never
     * free-types a name here.
     *
     * @return list<array{id: string, name: string, url: string}>
     */
    public function searchDestinations(BackupDestinationConnection $connection, string $query): array;

    /**
     * Resolves and persists $id/$name as $connection's chosen shared
     * location (site_id/site_name/drive_id) — the one thing that moves a
     * connection from "connected" to "connected and ready to use".
     */
    public function selectDestination(BackupDestinationConnection $connection, string $id, string $name): void;

    /**
     * Uploads $content to $path (folders created as needed) in the firm's
     * chosen shared location. Must throw if no location has been selected
     * yet rather than falling back to somewhere unintended.
     */
    public function uploadFile(BackupDestinationConnection $connection, string $path, string $content): void;
}
