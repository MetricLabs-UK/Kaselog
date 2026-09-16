<?php

namespace Tests\Feature\Jobs;

use App\Enums\ClientSource;
use App\Enums\DocumentAiSummaryStatus;
use App\Enums\MatterStatus;
use App\Jobs\SummarizeMatterDocument;
use App\Models\Client;
use App\Models\DocumentAiSummary;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\User;
use App\Notifications\DocumentFieldReviewNotification;
use App\Services\DocumentFieldComparisonService;
use App\Services\DocumentSummaryService;
use App\Services\PdfTextExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class SummarizeMatterDocumentTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private User $solicitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
        Storage::fake('documents');
        Notification::fake();
        $this->solicitor = User::factory()->create();
    }

    private function makeMatterDocument(string $filename = 'witness-statement.pdf', array $clientOverrides = []): MatterDocument
    {
        $client = Client::create(array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ], $clientOverrides));

        $matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
            'assigned_user_id' => $this->solicitor->id,
        ]);

        return MatterDocument::create([
            'matter_id' => $matter->id,
            'uploaded_by_type' => 'user',
            'uploaded_by_id' => 1,
            'filename' => $filename,
            'path' => "matter-docs/{$matter->id}/{$filename}",
            'visible_to_client' => false,
        ]);
    }

    /**
     * Builds a byte-accurate minimal PDF with a real text layer — smalot/
     * pdfparser needs a correct xref table, not just PDF-shaped syntax.
     */
    private function buildPdfBytes(string $line): string
    {
        $objects = [
            1 => "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            2 => "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            3 => "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>\nendobj\n",
            4 => "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $stream = "BT\n/F1 12 Tf\n72 720 Td\n({$line}) Tj\nET";
        $objects[5] = "5 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n";

        return $this->assemblePdf($objects);
    }

    /**
     * An empty stream still needs a valid xref — this produces a
     * structurally valid PDF with no text-showing operator, standing in for
     * a scanned/image-only PDF without needing real image data.
     */
    private function buildTextlessPdfBytes(): string
    {
        $objects = [
            1 => "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            2 => "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            3 => "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << >> /MediaBox [0 0 612 792] /Contents 4 0 R >>\nendobj\n",
        ];

        $objects[4] = "4 0 obj\n<< /Length 0 >>\nstream\n\nendstream\nendobj\n";

        return $this->assemblePdf($objects);
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function assemblePdf(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];

        foreach ($objects as $num => $object) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefStart = strlen($pdf);
        $count = count($objects) + 1;
        $xref = "xref\n0 {$count}\n0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf.$xref."trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";
    }

    public function test_successfully_summarizes_a_pdf_with_a_text_layer(): void
    {
        $document = $this->makeMatterDocument();
        Storage::disk('documents')->put($document->path, $this->buildPdfBytes('Witness statement of John Smith, dated 12 March 2024.'));

        DocumentAiSummary::create([
            'matter_document_id' => $document->id,
            'status' => DocumentAiSummaryStatus::Pending,
        ]);

        Http::fake([
            '*' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'summary' => 'A witness statement from John Smith.',
                        'document_type' => 'witness statement',
                        'names' => ['John Smith'],
                        'dates' => ['12 March 2024'],
                        'monetary_figures' => [],
                    ]),
                ],
                'done_reason' => 'stop',
                'prompt_eval_count' => 10,
                'eval_count' => 20,
            ]),
        ]);

        (new SummarizeMatterDocument($document->id))->handle(
            app(PdfTextExtractionService::class),
            app(DocumentSummaryService::class),
            app(DocumentFieldComparisonService::class),
        );

        $summary = $document->aiSummary()->first();

        $this->assertSame(DocumentAiSummaryStatus::Completed, $summary->status);
        $this->assertSame('A witness statement from John Smith.', $summary->summary);
        $this->assertSame('witness statement', $summary->key_facts['document_type']);
        $this->assertSame(['John Smith'], $summary->key_facts['names']);
        $this->assertNull($summary->error_message);
        $this->assertNotNull($summary->processed_at);
    }

    public function test_marks_summary_as_failed_gracefully_when_ollama_is_unreachable(): void
    {
        $document = $this->makeMatterDocument();
        Storage::disk('documents')->put($document->path, $this->buildPdfBytes('Some extractable text.'));

        DocumentAiSummary::create([
            'matter_document_id' => $document->id,
            'status' => DocumentAiSummaryStatus::Pending,
        ]);

        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        (new SummarizeMatterDocument($document->id))->handle(
            app(PdfTextExtractionService::class),
            app(DocumentSummaryService::class),
            app(DocumentFieldComparisonService::class),
        );

        $summary = $document->aiSummary()->first();

        $this->assertSame(DocumentAiSummaryStatus::Failed, $summary->status);
        $this->assertSame('AI is unavailable right now. Try again shortly.', $summary->error_message);
    }

    public function test_marks_summary_as_failed_when_pdf_has_no_extractable_text(): void
    {
        $document = $this->makeMatterDocument('scanned.pdf');
        Storage::disk('documents')->put($document->path, $this->buildTextlessPdfBytes());

        DocumentAiSummary::create([
            'matter_document_id' => $document->id,
            'status' => DocumentAiSummaryStatus::Pending,
        ]);

        Http::fake();

        (new SummarizeMatterDocument($document->id))->handle(
            app(PdfTextExtractionService::class),
            app(DocumentSummaryService::class),
            app(DocumentFieldComparisonService::class),
        );

        $summary = $document->aiSummary()->first();

        $this->assertSame(DocumentAiSummaryStatus::Failed, $summary->status);
        $this->assertStringContainsString('scanned/image-only', $summary->error_message);
        Http::assertNothingSent();
    }

    private function fakeOllamaResponse(array $overrides = []): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => [
                    'content' => json_encode(array_merge([
                        'summary' => 'A witness statement.',
                        'document_type' => 'witness statement',
                        'names' => [],
                        'dates' => [],
                        'monetary_figures' => [],
                        'ni_number' => '',
                        'date_of_birth' => '',
                        'phone' => '',
                        'address' => '',
                        'court_reference' => '',
                    ], $overrides)),
                ],
                'done_reason' => 'stop',
                'prompt_eval_count' => 10,
                'eval_count' => 20,
            ]),
        ]);
    }

    public function test_a_clean_field_match_needs_no_review_and_sends_no_notification(): void
    {
        $document = $this->makeMatterDocument(clientOverrides: ['phone' => '07555123456']);
        Storage::disk('documents')->put($document->path, $this->buildPdfBytes('Some text.'));
        DocumentAiSummary::create(['matter_document_id' => $document->id, 'status' => DocumentAiSummaryStatus::Pending]);

        $this->fakeOllamaResponse(['phone' => '07555 123 456']);

        (new SummarizeMatterDocument($document->id))->handle(
            app(PdfTextExtractionService::class),
            app(DocumentSummaryService::class),
            app(DocumentFieldComparisonService::class),
        );

        $summary = $document->aiSummary()->first();

        $this->assertFalse($summary->needs_review);
        $this->assertNull($summary->review_reason);
        $this->assertSame('match', $summary->field_comparisons['phone']['status']);

        Notification::assertNothingSent();
    }

    public function test_a_blank_crm_field_the_document_can_fill_triggers_review_and_notifies_the_assigned_solicitor(): void
    {
        $document = $this->makeMatterDocument();
        Storage::disk('documents')->put($document->path, $this->buildPdfBytes('NI number AB123456C.'));
        DocumentAiSummary::create(['matter_document_id' => $document->id, 'status' => DocumentAiSummaryStatus::Pending]);

        $this->fakeOllamaResponse(['ni_number' => 'AB123456C']);

        (new SummarizeMatterDocument($document->id))->handle(
            app(PdfTextExtractionService::class),
            app(DocumentSummaryService::class),
            app(DocumentFieldComparisonService::class),
        );

        $summary = $document->aiSummary()->first();

        $this->assertTrue($summary->needs_review);
        $this->assertSame('blank_fill', $summary->field_comparisons['ni_number']['status']);
        $this->assertSame('AB123456C', $summary->extracted_fields['ni_number']);
        $this->assertNull($summary->reviewed_at);

        Notification::assertSentTo($this->solicitor, DocumentFieldReviewNotification::class);
    }

    public function test_a_field_mismatch_triggers_review(): void
    {
        $document = $this->makeMatterDocument(clientOverrides: ['phone' => '07555123456']);
        Storage::disk('documents')->put($document->path, $this->buildPdfBytes('Contact number 07999888777.'));
        DocumentAiSummary::create(['matter_document_id' => $document->id, 'status' => DocumentAiSummaryStatus::Pending]);

        $this->fakeOllamaResponse(['phone' => '07999888777']);

        (new SummarizeMatterDocument($document->id))->handle(
            app(PdfTextExtractionService::class),
            app(DocumentSummaryService::class),
            app(DocumentFieldComparisonService::class),
        );

        $summary = $document->aiSummary()->first();

        $this->assertTrue($summary->needs_review);
        $this->assertSame('mismatch', $summary->field_comparisons['phone']['status']);

        Notification::assertSentTo($this->solicitor, DocumentFieldReviewNotification::class);
    }

    public function test_marking_reviewed_dismisses_the_flag_without_touching_the_crm(): void
    {
        $document = $this->makeMatterDocument();
        Storage::disk('documents')->put($document->path, $this->buildPdfBytes('NI number AB123456C.'));
        DocumentAiSummary::create(['matter_document_id' => $document->id, 'status' => DocumentAiSummaryStatus::Pending]);
        $this->fakeOllamaResponse(['ni_number' => 'AB123456C']);

        (new SummarizeMatterDocument($document->id))->handle(
            app(PdfTextExtractionService::class),
            app(DocumentSummaryService::class),
            app(DocumentFieldComparisonService::class),
        );

        $director = $this->actingAsRole('director');
        $summary = $document->aiSummary()->first();
        $summary->markReviewed($director);

        $this->assertNotNull($summary->fresh()->reviewed_at);
        $this->assertSame($director->id, $summary->fresh()->reviewed_by);
        // The CRM field itself is untouched — reviewing is not applying.
        $this->assertNull($document->matter->client->fresh()->ni_number);
    }
}
