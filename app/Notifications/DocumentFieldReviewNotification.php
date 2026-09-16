<?php

namespace App\Notifications;

use App\Models\DocumentAiSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Feature A — sent only when DocumentFieldComparisonService found a genuine
 * mismatch or a blank CRM field the document could fill; a clean match never
 * reaches this at all (see DocumentAiSummary::booted()'s updated hook).
 */
class DocumentFieldReviewNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly DocumentAiSummary $summary,
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
        $document = $this->summary->matterDocument;
        $matter = $document->matter;

        return [
            'matter_id' => $matter->id,
            'matter_reference' => $matter->reference,
            'matter_document_id' => $document->id,
            'document_ai_summary_id' => $this->summary->id,
            'filename' => $document->filename,
            'message' => "AI review of {$document->filename} on matter {$matter->reference} found something worth checking: {$this->summary->review_reason}",
        ];
    }
}
