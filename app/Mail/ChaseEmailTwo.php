<?php

namespace App\Mail;

use App\Models\Instalment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChaseEmailTwo extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Instalment $instalment,
        public readonly int $daysOverdue,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Overdue payment — action required',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.chase-email-two',
        );
    }
}
