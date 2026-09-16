<?php

namespace App\Services\Sms;

use Twilio\Rest\Client;

/**
 * A thin wrapper around Twilio's SDK client — the one seam SmsService
 * depends on. Exists so tests can mock one plain method (send()) instead of
 * Twilio's own SDK object graph (Client::$messages is a magic/lazy property
 * backed by a whole Rest\Api\V2010\Account\MessageList domain object, not
 * something Mockery can stand in for cleanly). See
 * AppServiceProvider::register() for how this is bound — a real one from
 * config('services.twilio.*') normally, and one that throws if ever reached
 * unmocked during tests.
 */
class TwilioSmsClient
{
    public function __construct(protected Client $client) {}

    /**
     * @return string the Twilio message SID, for correlating with Twilio's
     *                 own delivery status/logs later
     */
    public function send(string $to, string $from, string $body): string
    {
        $message = $this->client->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ]);

        return $message->sid;
    }
}
