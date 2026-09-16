<?php

namespace App\Notifications;

use App\Models\BackupExport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupExportFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly BackupExport $export) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->export->client?->full_name ?? 'your firm';

        return (new MailMessage)
            ->error()
            ->subject('[Kaselog] Your backup could not be completed')
            ->line("The backup you requested for {$label} could not be completed.")
            ->line("Reason: {$this->export->failed_reason}")
            ->line('You can request it again — if it keeps failing, contact support.');
    }
}
