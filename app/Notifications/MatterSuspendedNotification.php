<?php

namespace App\Notifications;

use App\Models\Instalment;
use App\Models\Matter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MatterSuspendedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Matter $matter,
        public readonly Instalment $instalment,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'matter_id' => $this->matter->id,
            'matter_reference' => $this->matter->reference,
            'instalment_id' => $this->instalment->id,
            'message' => "Matter {$this->matter->reference} was suspended after 21 days of non-payment.",
        ];
    }
}
