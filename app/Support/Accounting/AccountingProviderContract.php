<?php

namespace App\Support\Accounting;

use App\Enums\AccountingProviderKey;
use App\Models\AccountingConnection;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * Everything a firm's chosen accounting integration needs to do, independent
 * of which one it actually is. Xero (XeroAccountingProvider) is the only
 * implementation today — adding Sage/QuickBooks/FreeAgent later means a new
 * class implementing this plus a new AccountingProviderRegistry entry,
 * nothing here or in any calling code should need to change.
 *
 * Not implemented for AccountingProviderKey::Manual — a firm on "manual"
 * never resolves a provider instance at all (see AccountingProviderRegistry),
 * since there is nothing here for "no integration" to do.
 */
interface AccountingProviderContract
{
    public function key(): AccountingProviderKey;

    /**
     * Where to send the firm's browser to authorize this connection. $state
     * is opaque to the provider — it's how the callback finds its way back
     * to the right Kase tenant (see AccountingOAuthController).
     */
    public function getAuthorizationUrl(string $state): string;

    /**
     * Exchanges an authorization code for a token pair and persists it onto
     * $connection (access/refresh token, expiry, and the provider's own
     * organisation id) — the one place a fresh connection's tokens get
     * written.
     */
    public function handleAuthorizationCallback(string $code, AccountingConnection $connection): void;

    /**
     * Refreshes $connection's access token if it's at or near expiry,
     * persisting the rotated pair immediately. A no-op if the current token
     * is still comfortably valid. Every other method on this interface that
     * makes a real API call must call this first.
     */
    public function ensureFreshToken(AccountingConnection $connection): void;

    /**
     * Resolves $client to a contact id in the provider, reusing
     * $client->provider_contact_id if already set. Otherwise searches the
     * provider (by that stored id first, then a reference like email, then
     * name) before creating a new contact — deliberately never relies on a
     * provider's own inline name-matching, which risks silent duplicates.
     * Persists the resolved id back onto $client before returning it.
     */
    public function findOrCreateContact(AccountingConnection $connection, Client $client): string;

    /**
     * Creates a real, live invoice for $invoice against the resolved contact
     * for its client — deliberately ignorant of whether $invoice was built
     * from an Instalment or a bundle of TimeEntry rows; see
     * Invoice::lineItemsData() for that normalization.
     */
    public function createInvoice(AccountingConnection $connection, Invoice $invoice): ProviderInvoiceResult;

    public function verifyWebhookSignature(Request $request): bool;

    /**
     * @return list<ProviderWebhookEvent>
     */
    public function parseWebhookEvents(Request $request): array;
}
