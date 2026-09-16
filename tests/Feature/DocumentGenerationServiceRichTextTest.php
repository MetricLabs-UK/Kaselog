<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Enums\PrecedentTemplateType;
use App\Models\Client;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\PrecedentTemplate;
use App\Services\DocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * The rich-text side of the spike this feature was built on: TipTap-authored
 * HTML, resolved against real merge field values and normalized into strict
 * XML, must actually import into PhpWord and produce a readable .docx —
 * proven here end-to-end rather than assumed from the earlier throwaway
 * spike script.
 */
class DocumentGenerationServiceRichTextTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private DocumentGenerationService $service;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->setUpTenant();
        $this->actingAsRole('solicitor');
        $this->service = app(DocumentGenerationService::class);

        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '07555123456', 'address' => '1 High Street, Leeds', 'source' => ClientSource::Phone,
        ]);

        $this->matter = Matter::create([
            'client_id' => $client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active,
        ]);
    }

    private function makeTemplate(string $content): PrecedentTemplate
    {
        return PrecedentTemplate::create([
            'name' => 'Client Care Letter',
            'template_key' => 'client_care',
            'type' => PrecedentTemplateType::RichText,
            'content' => $content,
            'available_fields' => [],
        ]);
    }

    public function test_generating_a_rich_text_template_produces_a_readable_docx_with_resolved_merge_tags(): void
    {
        $template = $this->makeTemplate(
            '<p>Dear <span data-type="mergeTag" data-id="client_name">Client name</span>,</p>'
            .'<p>This confirms we act for you on matter <span data-type="mergeTag" data-id="matter_reference">Matter reference</span>.</p>'
            .'<p>Regards,<br>Kase Legal</p>',
        );

        $document = $this->service->generate($this->matter, $template);

        $this->assertInstanceOf(GeneratedDocument::class, $document);
        Storage::disk('documents')->assertExists($document->file_path);

        // Round-trips through PhpWord's own reader too, proving the file is
        // a genuinely well-formed .docx and not just bytes on disk.
        IOFactory::load(Storage::disk('documents')->path($document->file_path));

        $text = $this->extractPlainText(Storage::disk('documents')->path($document->file_path));

        $this->assertStringContainsString('Dear Jane Doe', $text);
        $this->assertStringContainsString("matter {$this->matter->reference}", $text);
        $this->assertStringContainsString('Kase Legal', $text);
    }

    public function test_generation_also_creates_the_matter_document_record(): void
    {
        $template = $this->makeTemplate('<p>Dear <span data-type="mergeTag" data-id="client_name">Client name</span>.</p>');

        $document = $this->service->generate($this->matter, $template);

        $this->assertDatabaseHas('matter_documents', [
            'matter_id' => $this->matter->id,
            'filename' => $document->filename,
        ]);
        $this->assertSame(1, MatterDocument::where('matter_id', $this->matter->id)->count());
    }

    public function test_preview_renders_the_same_html_generation_would_use_without_creating_any_records(): void
    {
        $template = $this->makeTemplate('<p>Dear <span data-type="mergeTag" data-id="client_name">Client name</span>.</p>');

        $html = $this->service->renderRichTextHtml($template, $this->matter);

        $this->assertStringContainsString('Dear', trim(strip_tags($html)));
        $this->assertStringContainsString('Jane Doe', trim(strip_tags($html)));
        $this->assertSame(0, GeneratedDocument::count());
        $this->assertSame(0, MatterDocument::count());
    }

    public function test_a_field_the_template_never_references_is_simply_absent_from_the_output(): void
    {
        $template = $this->makeTemplate('<p>Dear <span data-type="mergeTag" data-id="client_name">Client name</span>.</p>');

        $html = $this->service->renderRichTextHtml($template, $this->matter);

        $this->assertStringNotContainsString('opponent_name', $html);
    }

    private function extractPlainText(string $absoluteDocxPath): string
    {
        $zip = new \ZipArchive;
        $zip->open($absoluteDocxPath);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $withSpaces = str_replace('</w:p>', ' ', $xml);

        return trim(preg_replace('/\s+/', ' ', strip_tags($withSpaces)));
    }
}
