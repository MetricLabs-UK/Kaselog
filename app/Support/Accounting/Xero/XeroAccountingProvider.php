<?php

namespace App\Support\Accounting\Xero;

use App\Enums\AccountingProviderKey;
use App\Models\AccountingConnection;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Accounting\AccountingProviderContract;
use App\Support\Accounting\ProviderInvoiceResult;
use App\Support\Accounting\ProviderWebhookEvent;
use Calcinai\OAuth2\Client\Provider\Xero as XeroOAuthProvider;
use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Request;
use XeroAPI\XeroPHP\Api\AccountingApi;
use XeroAPI\XeroPHP\Configuration;
use XeroAPI\XeroPHP\Models\Accounting\Contact;
use XeroAPI\XeroPHP\Models\Accounting\Contacts;
use XeroAPI\XeroPHP\Models\Accounting\Invoice as XeroInvoice;
use XeroAPI\XeroPHP\Models\Accounting\Invoices;
use XeroAPI\XeroPHP\Models\Accounting\LineItem;
use XeroAPI\XeroPHP\Models\Accounting\Phone;

/**
 * The first (and, as of Section 20, only) real AccountingProviderContract
 * implementation. Built on calcinai/oauth2-xero (a League OAuth2 client
 * provider for Xero's auth flow) and xeroapi/xero-php-oauth2 (Xero's own
 * officially-maintained, OpenAPI-generated API client for Contacts/
 * Invoices/etc.) — deliberately not hand-rolled, both are actively
 * maintained and generated/reviewed against Xero's own spec.
 */
class XeroAccountingProvider implements AccountingProviderContract
{
    /**
     * offline_access is what makes Xero issue a refresh token at all —
     * without it the connection would die the moment the 30-minute access
     * token expired. accounting.contacts/accounting.transactions are the
     * two scopes Contact and Invoice creation actually need.
     */
    private const SCOPES = 'openid profile email offline_access accounting.contacts accounting.transactions';

    public function key(): AccountingProviderKey
    {
        return AccountingProviderKey::Xero;
    }

    public function getAuthorizationUrl(string $state): string
    {
        return $this->oauthProvider()->getAuthorizationUrl([
            'scope' => self::SCOPES,
            'state' => $state,
        ]);
    }

    public function handleAuthorizationCallback(string $code, AccountingConnection $connection): void
    {
        $oauthProvider = $this->oauthProvider();
        $token = $oauthProvider->getAccessToken('authorization_code', ['code' => $code]);

        // A firm authorizing its own single Xero organisation gets exactly
        // one entry back here — if they have access to more than one (e.g.
        // an accountant's own multi-org login), the first is what gets
        // connected; picking a specific one is a later refinement, not
        // needed for a firm connecting its own single set of books.
        $tenants = $oauthProvider->getTenants($token);
        $tenant = $tenants[0] ?? null;

        $connection->forceFill([
            'provider' => AccountingProviderKey::Xero,
            'access_token' => $token->getToken(),
            'refresh_token' => $token->getRefreshToken(),
            'token_expires_at' => Carbon::createFromTimestamp($token->getExpires()),
            'external_org_id' => $tenant?->tenantId,
            'connected_at' => now(),
            'disconnected_at' => null,
        ])->save();
    }

    /**
     * Refreshes and immediately persists the rotated pair if within ~5
     * minutes of expiry (AccountingConnection::tokenNeedsRefresh()) — a
     * stale refresh token is never reused, matching Xero's own requirement
     * that each refresh's new refresh token replace the old one. Called by
     * every method below that makes a real API call, plus the daily
     * scheduled sweep (RefreshAccountingConnections) for tenants who simply
     * haven't sent anything recently.
     */
    public function ensureFreshToken(AccountingConnection $connection): void
    {
        if (! $connection->tokenNeedsRefresh()) {
            return;
        }

        $token = $this->oauthProvider()->getAccessToken('refresh_token', [
            'refresh_token' => $connection->refresh_token,
        ]);

        $connection->forceFill([
            'access_token' => $token->getToken(),
            'refresh_token' => $token->getRefreshToken(),
            'token_expires_at' => Carbon::createFromTimestamp($token->getExpires()),
        ])->save();
    }

    /**
     * Never relies on Xero's inline Contact-by-Name auto-match/auto-create
     * (Invoice payloads can take just a Name and Xero will guess) — that
     * risks a near-miss name variation silently creating a duplicate
     * contact. Resolution order: the id Kase already has on file, then an
     * exact email match, then an exact name match, only creating a new
     * contact if none of those found anything.
     */
    public function findOrCreateContact(AccountingConnection $connection, Client $client): string
    {
        $this->ensureFreshToken($connection);

        if (filled($client->provider_contact_id)) {
            return $client->provider_contact_id;
        }

        $api = $this->accountingApi($connection);
        $orgId = $connection->external_org_id;

        if (filled($client->email)) {
            $existing = $this->findContactByWhere($api, $orgId, 'EmailAddress=="'.addslashes($client->email).'"');

            if ($existing !== null) {
                $client->update(['provider_contact_id' => $existing]);

                return $existing;
            }
        }

        $existing = $this->findContactByWhere($api, $orgId, 'Name=="'.addslashes($client->full_name).'"');

        if ($existing !== null) {
            $client->update(['provider_contact_id' => $existing]);

            return $existing;
        }

        $contact = new Contact;
        $contact->setName($client->full_name);

        if (filled($client->email)) {
            $contact->setEmailAddress($client->email);
        }

        if (filled($client->phone)) {
            $phone = new Phone;
            $phone->setPhoneType(Phone::PHONE_TYPE__DEFAULT);
            $phone->setPhoneNumber($client->phone);
            $contact->setPhones([$phone]);
        }

        $created = $api->createContacts($orgId, new Contacts(['contacts' => [$contact]]));
        $newId = $created->getContacts()[0]->getContactId();

        $client->update(['provider_contact_id' => $newId]);

        return $newId;
    }

    private function findContactByWhere(AccountingApi $api, string $orgId, string $where): ?string
    {
        $result = $api->getContacts($orgId, null, $where);
        $found = $result->getContacts()[0] ?? null;

        return $found?->getContactId();
    }

    public function createInvoice(AccountingConnection $connection, Invoice $invoice): ProviderInvoiceResult
    {
        $this->ensureFreshToken($connection);

        $contactId = $this->findOrCreateContact($connection, $invoice->client);

        $lineItems = collect($invoice->lineItemsData())->map(function (array $data): LineItem {
            $lineItem = new LineItem;
            $lineItem->setDescription($data['description']);
            $lineItem->setQuantity($data['quantity']);
            $lineItem->setUnitAmount($data['unitAmount']);

            return $lineItem;
        })->all();

        foreach ($lineItems as $lineItem) {
            $lineItem->setAccountCode($connection->account_code);
        }

        $contact = new Contact;
        $contact->setContactId($contactId);

        // A time-entry bundle has no natural due date the way an instalment
        // does (its due_date is the schedule the client already agreed to)
        // — 14 days is a reasonable default payment term, not derived from
        // anything the client has agreed; a firm wanting different terms
        // configures that in Xero itself.
        $dueDate = $invoice->instalments->first()?->due_date?->toDateString()
            ?? now()->addDays(14)->toDateString();

        $xeroInvoice = new XeroInvoice;
        $xeroInvoice->setType(XeroInvoice::TYPE_ACCREC);
        $xeroInvoice->setContact($contact);
        $xeroInvoice->setLineItems($lineItems);
        $xeroInvoice->setDueDate($dueDate);
        $xeroInvoice->setReference($invoice->matter->reference);
        $xeroInvoice->setStatus(XeroInvoice::STATUS_AUTHORISED);

        $api = $this->accountingApi($connection);
        $result = $api->createInvoices($connection->external_org_id, new Invoices(['invoices' => [$xeroInvoice]]));
        $created = $result->getInvoices()[0];

        return new ProviderInvoiceResult(
            id: $created->getInvoiceId(),
            number: $created->getInvoiceNumber(),
        );
    }

    /**
     * Same HMAC-SHA256-over-raw-body scheme XeroWebhookController already
     * implemented — moved here so it lives with the rest of the Xero-
     * specific logic rather than the (now thin) controller.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = config('services.xero.webhook_secret');
        $signature = $request->header('x-xero-signature');

        if (blank($secret) || blank($signature)) {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), (string) $secret, true));

        return hash_equals($computed, $signature);
    }

    /**
     * @return list<ProviderWebhookEvent>
     */
    public function parseWebhookEvents(Request $request): array
    {
        $events = [];

        foreach ((array) $request->input('events', []) as $event) {
            if (($event['eventCategory'] ?? null) !== 'INVOICE' || ($event['eventType'] ?? null) !== 'UPDATE') {
                continue;
            }

            if (blank($event['resourceId'] ?? null)) {
                continue;
            }

            $events[] = new ProviderWebhookEvent(
                externalInvoiceId: $event['resourceId'],
                externalOrgId: $event['tenantId'] ?? null,
            );
        }

        return $events;
    }

    private function oauthProvider(): XeroOAuthProvider
    {
        return new XeroOAuthProvider([
            'clientId' => config('services.xero.client_id'),
            'clientSecret' => config('services.xero.client_secret'),
            'redirectUri' => route('integrations.accounting.callback', ['provider' => 'xero']),
        ]);
    }

    private function accountingApi(AccountingConnection $connection): AccountingApi
    {
        $config = Configuration::getDefaultConfiguration()->setAccessToken($connection->access_token);

        return new AccountingApi(new GuzzleClient, $config);
    }
}
