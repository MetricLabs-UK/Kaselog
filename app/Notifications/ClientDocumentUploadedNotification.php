<?php

namespace App\Notifications;

use App\Models\Matter;
use App\Models\MatterDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Section 3 — the Portal's client-upload flow has no approval gate (a
 * client-uploaded document lands directly in the Matter's Documents tab,
 * same as a staff upload), so this notification is the only signal staff
 * get that something new has arrived — see Matter::notifiableStaffUsers()
 * for who receives it.
 */
class ClientDocumentUploadedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Matter $matter,
        public readonly MatterDocument $document,
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
            'matter_document_id' => $this->document->id,
            'filename' => $this->document->filename,
            'message' => "The client uploaded a new document to matter {$this->matter->reference}: {$this->document->filename}.",
        ];
    }
}
