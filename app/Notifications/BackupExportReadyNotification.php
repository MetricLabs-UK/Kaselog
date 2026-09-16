<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\BackupExport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail only, not database — this app has no notification bell UI wired up
 * (see CallNeedsReviewNotification's docblock), so a database-channel
 * notification would just sit invisibly in the table. Mail also reaches the
 * requester whether or not they're still on the page once a large export
 * finishes, which a same-request response never could anyway.
 */
class BackupExportReadyNotification extends Notification
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

        $mail = (new MailMessage)
            ->subject('[Kaselog] Your backup is ready to download')
            ->line("The backup you requested for {$label} is ready.")
            ->line('It will be available to download for 7 days.');

        if ($this->export->client) {
            $mail->action('Go to client record', ClientResource::getUrl('view', ['record' => $this->export->client], panel: 'admin', tenant: $this->export->tenant));
        }

        return $mail;
    }
}
