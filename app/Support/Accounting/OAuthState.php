<?php

namespace App\Support\Accounting;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The OAuth2 `state` parameter's actual job here: Xero's redirect back to
 * Kase hits one shared callback route regardless of which firm started the
 * flow, so state is how that callback finds its way back to the right
 * tenant — encrypted (not just base64, so it can't be tampered with to
 * target a different tenant) and short-lived. Provider-agnostic: whichever
 * AccountingProviderContract is used, the state it's handed is built and
 * verified the same way.
 */
final class OAuthState
{
    private const TTL_MINUTES = 10;

    public static function generate(Tenant $tenant, User $user): string
    {
        return Crypt::encryptString(json_encode([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'nonce' => Str::random(16),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ]));
    }

    /**
     * @return array{tenant_id: int, user_id: int}
     */
    public static function verify(string $state, User $currentUser): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), associative: true);
        } catch (\Throwable) {
            throw new RuntimeException('Invalid or tampered OAuth state.');
        }

        if (! is_array($payload) || now()->timestamp > ($payload['expires_at'] ?? 0)) {
            throw new RuntimeException('This connection attempt has expired — please try connecting again.');
        }

        if ((int) ($payload['user_id'] ?? 0) !== $currentUser->id) {
            throw new RuntimeException('This connection attempt belongs to a different user.');
        }

        return [
            'tenant_id' => (int) $payload['tenant_id'],
            'user_id' => (int) $payload['user_id'],
        ];
    }
}
