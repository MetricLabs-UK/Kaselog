<?php

namespace App\Services;

use App\Mail\PortalInviteMail;
use App\Models\Matter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

class PortalInviteService
{
    public function __construct(protected SmsService $smsService) {}

    /**
     * Issues (or reissues) a portal invite for the matter's client. Always
     * generates a fresh portal_token, which — since SetPortalPassword looks
     * the client up by that token — silently invalidates any previously
     * issued link. Same operation for the first invite and a staff-triggered
     * resend; the two "Access to Portal" / "Resend invite" actions differ
     * only in when they're shown to staff.
     */
    public function invite(Matter $matter): void
    {
        $client = $matter->client;

        if (! $client) {
            throw new RuntimeException("Matter {$matter->reference} has no client to invite.");
        }

        $client->forceFill([
            'portal_token' => Str::random(64),
        ])->save();

        $expiresAt = now()->addHours((int) config('kaselog.portal_invite_expiry_hours'));

        $signedUrl = URL::temporarySignedRoute(
            'filament.portal.pages.set-password',
            $expiresAt,
            [
                'tenant' => $matter->tenant->slug,
                'token' => $client->portal_token,
                'matter' => $matter->reference,
            ],
        );

        Mail::to($client->email)->send(new PortalInviteMail($matter, $client, $signedUrl, $expiresAt));

        if (filled($client->phone)) {
            $this->smsService->portalInvite($client, $matter, $signedUrl);
        }
    }
}
