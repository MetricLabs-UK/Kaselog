<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\ProcessAccountingPaymentWebhook;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Section 20 — there was previously no HTTP-level test for this controller
 * at all (only the job's business logic, in isolation). Signature
 * verification and event parsing now live on XeroAccountingProvider; this
 * confirms the controller wires them together correctly.
 */
class XeroWebhookControllerTest extends TestCase
{
    private const SECRET = 'test-xero-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.xero.webhook_secret' => self::SECRET]);
    }

    private function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, self::SECRET, true));
    }

    public function test_a_validly_signed_invoice_update_event_dispatches_the_job(): void
    {
        Bus::fake();

        $payload = [
            'events' => [
                ['eventCategory' => 'INVOICE', 'eventType' => 'UPDATE', 'resourceId' => 'INV-1', 'tenantId' => 'org-1'],
            ],
        ];
        $body = json_encode($payload);

        $this->call('POST', '/webhooks/xero', server: [
            'HTTP_x-xero-signature' => $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertOk();

        Bus::assertDispatched(ProcessAccountingPaymentWebhook::class, fn ($job) => $job->event->externalInvoiceId === 'INV-1'
            && $job->event->externalOrgId === 'org-1');
    }

    public function test_an_invalid_signature_is_rejected_and_dispatches_nothing(): void
    {
        Bus::fake();

        $body = json_encode(['events' => [['eventCategory' => 'INVOICE', 'eventType' => 'UPDATE', 'resourceId' => 'INV-1']]]);

        $this->call('POST', '/webhooks/xero', server: [
            'HTTP_x-xero-signature' => 'not-a-real-signature',
            'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertStatus(401);

        Bus::assertNotDispatched(ProcessAccountingPaymentWebhook::class);
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $body = json_encode(['events' => []]);

        $this->call('POST', '/webhooks/xero', content: $body)->assertStatus(401);
    }

    public function test_a_non_invoice_update_event_is_ignored(): void
    {
        Bus::fake();

        $payload = ['events' => [['eventCategory' => 'CONTACT', 'eventType' => 'UPDATE', 'resourceId' => 'CONTACT-1']]];
        $body = json_encode($payload);

        $this->call('POST', '/webhooks/xero', server: [
            'HTTP_x-xero-signature' => $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertOk();

        Bus::assertNotDispatched(ProcessAccountingPaymentWebhook::class);
    }
}
