<?php

namespace App\Notifications;

use App\Enums\ReconciliationIssueReason;
use App\Filament\Admin\Resources\AccountingReconciliationIssues\AccountingReconciliationIssueResource;
use App\Models\AccountingReconciliationIssue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired from AccountingReconciliationIssue::booted() the moment a genuinely
 * unresolved issue is created — mail, not database, matching
 * CallNeedsReviewNotification's reasoning (needs to reach a director whether
 * or not they're currently logged in; Filament's notification bell isn't
 * enabled in this app).
 */
class AccountingReconciliationIssueNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly AccountingReconciliationIssue $issue) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[Kaselog] An accounting record needs manual review')
            ->line($this->summaryLine())
            ->action('Review this issue', AccountingReconciliationIssueResource::getUrl('view', ['record' => $this->issue], panel: 'admin', tenant: $this->issue->tenant));

        if ($this->issue->external_invoice_id) {
            $mail->line("Provider invoice ID: {$this->issue->external_invoice_id}");
        }

        return $mail;
    }

    private function summaryLine(): string
    {
        return match ($this->issue->reason) {
            ReconciliationIssueReason::WebhookUnmatched => 'A payment notification from '.$this->issue->provider->getLabel().' didn\'t match any instalment in Kaselog.',
            ReconciliationIssueReason::InstalmentEditedAfterSend => 'An instalment was edited after already being sent to '.$this->issue->provider->getLabel().' — it may now be out of sync.',
            ReconciliationIssueReason::PaymentPlanEditedAfterSend => 'A payment plan was edited after one of its instalments was already sent to '.$this->issue->provider->getLabel().' — it may now be out of sync.',
        };
    }
}
