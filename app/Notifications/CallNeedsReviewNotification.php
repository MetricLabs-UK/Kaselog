<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\CallNotes\CallNoteResource;
use App\Models\CallNote;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired from CallNote::booted() the moment a call note is created with
 * needs_review already true (RetellWebhookController sets it when a call
 * can't be confidently matched to a matter/client) — mail, not database,
 * because this needs to reach someone whether or not they're currently
 * logged into the admin panel, and Filament's own notification bell isn't
 * enabled in this app (the two other existing ->via(['database']))
 * notifications, MatterSuspended/MatterReactivated, aren't surfaced
 * anywhere in the UI either — worth knowing, not fixed here).
 */
class CallNeedsReviewNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly CallNote $callNote) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $caller = $this->callNote->client?->full_name
            ?? $this->callNote->lead?->full_name
            ?? 'Unknown caller';

        return (new MailMessage)
            ->subject('[Kaselog] A call needs manual review')
            ->line("A call from Retell couldn't be automatically matched and needs manual review.")
            ->line("Caller: {$caller}")
            ->line("Reason: {$this->callNote->review_reason}")
            ->when($this->callNote->summary, fn (MailMessage $mail) => $mail->line("Call summary: {$this->callNote->summary}"))
            ->action('Review this call', CallNoteResource::getUrl('view', ['record' => $this->callNote], panel: 'admin', tenant: $this->callNote->tenant));
    }
}
