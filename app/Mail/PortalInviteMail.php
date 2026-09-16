<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Matter;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Matter $matter,
        public readonly Client $client,
        public readonly string $signedUrl,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Set up your {$this->matter->tenant->name} client portal account",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.portal-invite',
        );
    }
}
