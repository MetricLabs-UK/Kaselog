<?php

namespace App\Services;

use App\Enums\DocumentAiSummaryStatus;
use App\Models\Matter;

/**
 * Assembles the plain-text context passed to Quill for a matter — matter
 * metadata plus any completed Part-1 document summaries. Deliberately not
 * RAG: no chunking, no embeddings, no retrieval — everything available for
 * this matter is just concatenated into one block, capped defensively so it
 * doesn't overflow Ollama's context window. Quill never queries the
 * database itself; this is the only data it ever sees.
 */
class QuillPromptContextBuilder
{
    private const MAX_CHARS = 12000;

    public function build(Matter $matter): string
    {
        $lines = [
            "Matter reference: {$matter->reference}",
            'Client: '.($matter->client?->full_name ?? 'Unknown'),
            "Practice area: {$matter->practice_area}",
            "Status: {$matter->status->value}",
        ];

        if ($matter->court_date) {
            $lines[] = "Court date: {$matter->court_date->format('d/m/Y')}";
        }

        $summarizedDocuments = $matter->documents()
            ->whereHas('aiSummary', fn ($query) => $query->where('status', DocumentAiSummaryStatus::Completed))
            ->with('aiSummary')
            ->get();

        if ($summarizedDocuments->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Document summaries:';

            foreach ($summarizedDocuments as $document) {
                $lines[] = "- {$document->filename}: {$document->aiSummary->summary}";
            }
        }

        return mb_substr(implode("\n", $lines), 0, self::MAX_CHARS);
    }
}
