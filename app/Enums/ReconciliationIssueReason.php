<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why an AccountingReconciliationIssue row exists — the two failure modes
 * this queue exists to catch, per Section 20's sign-off: a webhook event
 * that couldn't be matched to any instalment, and an instalment/PaymentPlan
 * edited after it was already sent to the provider (silent drift).
 */
enum ReconciliationIssueReason: string implements HasLabel
{
    case WebhookUnmatched = 'webhook_unmatched';
    case InstalmentEditedAfterSend = 'instalment_edited_after_send';
    case PaymentPlanEditedAfterSend = 'payment_plan_edited_after_send';
    case TimeEntryEditedAfterSend = 'time_entry_edited_after_send';

    public function getLabel(): string
    {
        return match ($this) {
            self::WebhookUnmatched => 'Webhook did not match any invoice',
            self::InstalmentEditedAfterSend => 'Instalment edited after being sent',
            self::PaymentPlanEditedAfterSend => 'Payment plan edited after an instalment was sent',
            self::TimeEntryEditedAfterSend => 'Time entry edited after its invoice was sent',
        };
    }
}
