<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AccountingProviderKey;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAccountingPaymentWebhook;
use App\Support\Accounting\AccountingProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Signature verification and event-shape parsing now live on
 * XeroAccountingProvider (App\Support\Accounting\AccountingProviderContract)
 * rather than here — this controller is just Xero's own webhook entry
 * point, thin by design. A second provider would get its own webhook route
 * and controller (each provider's delivery scheme is genuinely different),
 * but both would dispatch into the same ProcessAccountingPaymentWebhook job.
 */
class XeroWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $provider = AccountingProviderRegistry::get(AccountingProviderKey::Xero);

        if (! $provider->verifyWebhookSignature($request)) {
            Log::warning('Xero webhook: invalid signature.');

            return response('', 401);
        }

        foreach ($provider->parseWebhookEvents($request) as $event) {
            ProcessAccountingPaymentWebhook::dispatch(AccountingProviderKey::Xero, $event);
        }

        return response('', 200);
    }
}
