<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to Spatie\Backup\Notifications\Notifiable — the same
 * config('backup.notifications.mail.to') recipient spatie/laravel-backup's
 * own BackupHasFailedNotification uses, so there's one alert inbox for both
 * the weekly backup and this nightly incremental document sync, not two to
 * separately remember to check.
 */
class DocumentSyncFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $failedCount,
        public readonly int $syncedCount,
        public readonly ?string $lastError,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('[Kaselog] Nightly document sync to SharePoint failed')
            ->line("{$this->failedCount} file(s) failed to sync to SharePoint tonight ({$this->syncedCount} succeeded).")
            ->when($this->lastError, fn (MailMessage $mail) => $mail->line("Last error: {$this->lastError}"))
            ->line('Check the application log for the full list — each failure is logged individually with the model and record ID.');
    }
}
