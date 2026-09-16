<?php

namespace App\Support;

/**
 * Reduces a phone number to its last 10 digits so "+447585637580",
 * "07585 637580" and "00447585637580" all compare equal. Originally inline
 * in RetellWebhookController (matching an inbound caller against a Client
 * within one already-resolved tenant); shared here so Section 18 item 4's
 * Hub-wide phone lookup (matching a number across *every* tenant) can't drift
 * from the same rule.
 */
final class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        $last10 = substr($digits, -10);

        return $last10 !== '' ? $last10 : null;
    }
}
