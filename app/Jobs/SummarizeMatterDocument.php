<?php

namespace App\Jobs;

use App\Enums\DocumentAiSummaryStatus;
use App\Models\DocumentAiSummary;
use App\Models\MatterDocument;
use App\Services\DocumentFieldComparisonService;
use App\Services\DocumentSummaryService;
use App\Services\PdfTextExtractionService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extracts PDF text and calls Ollama for a summary + key facts, queued
 * because both extraction and inference can run past what's reasonable to
 * block a Filament action on — see DocumentSummaryService for the AI call
 * itself. Only the document's id is carried in the payload (not the model)
 * so queue serialization doesn't try to re-fetch it through the fail-closed
 * TenantScope before this job has had a chance to set the tenant context.
 */
class SummarizeMatterDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 10;

    public int $timeout = 150;

    public function __construct(public readonly int $matterDocumentId) {}

    public function handle(PdfTextExtractionService $extractor, DocumentSummaryService $summarizer, DocumentFieldComparisonService $comparator): void
    {
        // A queue worker is a separate process from whatever request
        // dispatched this job — tenant context never carries over, so it
        // has to be re-derived from the document itself.
        $document = MatterDocument::allTenants()->find($this->matterDocumentId);

        if (! $document) {
            Log::warning("SummarizeMatterDocument: matter document {$this->matterDocumentId} no longer exists.");

            return;
        }

        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($document->tenant);

        try {
            $this->process($document, $extractor, $summarizer, $comparator);
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }

    private function process(MatterDocument $document, PdfTextExtractionService $extractor, DocumentSummaryService $summarizer, DocumentFieldComparisonService $comparator): void
    {
        // Normally already created (Pending) by the upload action so the UI
        // has something to poll on immediately — created here too as a
        // fallback for a manually re-dispatched job.
        $summary = $document->aiSummary ?? DocumentAiSummary::create([
            'matter_document_id' => $document->id,
            'status' => DocumentAiSummaryStatus::Pending,
        ]);

        $text = $extractor->extract(Storage::disk('documents')->path($document->path));

        if ($text === null) {
            $summary->update([
                'status' => DocumentAiSummaryStatus::Failed,
                'error_message' => "No extractable text found in this PDF — it may be a scanned/image-only document (OCR isn't supported yet).",
                'processed_at' => now(),
            ]);

            return;
        }

        try {
            $facts = $summarizer->summarize($text);
        } catch (Throwable $exception) {
            Log::error("SummarizeMatterDocument: AI call failed for matter document {$document->id}: {$exception->getMessage()}");

            $summary->update([
                'status' => DocumentAiSummaryStatus::Failed,
                'error_message' => 'AI is unavailable right now. Try again shortly.',
                'processed_at' => now(),
            ]);

            return;
        }

        // Feature A — compares the extraction against this matter's client
        // (and the matter itself, for court_reference/urn). Deliberately
        // computed and saved in the same update as the summary itself: a
        // clean comparison shouldn't look any different from today's
        // behaviour (needs_review defaults false, nothing to notify), and a
        // flagged one relies on this same save triggering DocumentAiSummary's
        // own updated hook.
        $comparison = $comparator->compare($facts, $document->matter->client, $document->matter);

        $summary->update([
            'status' => DocumentAiSummaryStatus::Completed,
            'summary' => $facts['summary'] ?? '',
            'key_facts' => [
                'document_type' => $facts['document_type'] ?? '',
                'names' => $facts['names'] ?? [],
                'dates' => $facts['dates'] ?? [],
                'monetary_figures' => $facts['monetary_figures'] ?? [],
            ],
            'extracted_fields' => $comparison['extracted_fields'],
            'field_comparisons' => $comparison['field_comparisons'],
            'needs_review' => $comparison['needs_review'],
            'review_reason' => $comparison['review_reason'],
            'error_message' => null,
            'processed_at' => now(),
        ]);
    }

    /**
     * Reached only if handle() throws past retries for a reason other than
     * the AI call itself (that path above already resolves to a clean
     * `failed` state without needing this) — e.g. a bug in extraction.
     * Still leaves the record in a resolved state rather than stuck
     * "processing" forever.
     */
    public function failed(?Throwable $exception): void
    {
        $document = MatterDocument::allTenants()->find($this->matterDocumentId);

        if (! $document) {
            return;
        }

        // aiSummary() is tenant-scoped like everything else — this runs as
        // its own invocation, not nested inside handle(), so the tenant
        // context set there is long gone by the time this fires.
        $previousTenant = CurrentTenant::get();
        CurrentTenant::set($document->tenant);

        try {
            $document->aiSummary?->update([
                'status' => DocumentAiSummaryStatus::Failed,
                'error_message' => 'Something went wrong generating this summary. Try again.',
                'processed_at' => now(),
            ]);
        } finally {
            CurrentTenant::set($previousTenant);
        }
    }
}
