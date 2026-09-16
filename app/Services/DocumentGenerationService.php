<?php

namespace App\Services;

use App\Enums\PrecedentTemplateType;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\PrecedentTemplate;
use DOMDocument;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

class DocumentGenerationService
{
    public function generate(Matter $matter, PrecedentTemplate $template): GeneratedDocument
    {
        $disk = Storage::disk('documents');
        $directory = "matter-docs/{$matter->id}";
        $filename = $this->buildFilename($matter, $template);
        $relativePath = "{$directory}/{$filename}";

        $disk->makeDirectory($directory);

        match ($template->type) {
            PrecedentTemplateType::DocxUpload => $this->generateFromDocxUpload($matter, $template, $disk->path($relativePath)),
            PrecedentTemplateType::RichText => $this->generateFromRichText($matter, $template, $disk->path($relativePath)),
        };

        MatterDocument::create([
            'matter_id' => $matter->id,
            'uploaded_by_type' => 'user',
            'uploaded_by_id' => auth()->id(),
            'filename' => $filename,
            'path' => $relativePath,
            'visible_to_client' => false,
        ]);

        return GeneratedDocument::create([
            'matter_id' => $matter->id,
            'precedent_template_id' => $template->id,
            'generated_by_user_id' => auth()->id(),
            'filename' => $filename,
            'file_path' => $relativePath,
            'generated_at' => now(),
        ]);
    }

    private function generateFromDocxUpload(Matter $matter, PrecedentTemplate $template, string $absolutePath): void
    {
        $disk = Storage::disk('documents');

        if (! $template->file_path || ! $disk->exists($template->file_path)) {
            throw new RuntimeException("Template file not found: {$template->file_path}");
        }

        $processor = new TemplateProcessor($disk->path($template->file_path));
        $fields = $this->resolveFields($matter);

        foreach ($template->available_fields as $field) {
            $processor->setValue($field, e($fields[$field] ?? ''));
        }

        $processor->saveAs($absolutePath);
    }

    /**
     * The rich-text pipeline verified in the PhpWord/TipTap spike: render
     * the template's HTML with real merge tag values resolved, normalize it
     * into strict XML (PhpWord's Html::addHtml() uses DOMDocument::loadXML()
     * internally, which rejects lenient HTML5 like a bare <br>), then import
     * it into a fresh PhpWord document.
     */
    private function generateFromRichText(Matter $matter, PrecedentTemplate $template, string $absolutePath): void
    {
        $html = $this->renderRichTextHtml($template, $matter);

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        Html::addHtml($section, $this->normalizeHtmlForPhpWord($html));

        IOFactory::createWriter($phpWord, 'Word2007')->save($absolutePath);
    }

    /**
     * Shared by real generation and the preview action — resolves the same
     * merge field values either way, so what a solicitor previews against a
     * real matter is exactly what generation would produce.
     */
    public function renderRichTextHtml(PrecedentTemplate $template, Matter $matter): string
    {
        return RichContentRenderer::make($template->content ?? '')
            ->mergeTags($this->resolveFields($matter))
            ->toHtml();
    }

    private function normalizeHtmlForPhpWord(string $html): string
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        return $document->saveXML($document->documentElement);
    }

    /**
     * @return array<string, string>
     */
    public function resolveFields(Matter $matter): array
    {
        $client = $matter->client;
        $paymentPlan = $matter->paymentPlan;
        $legalEntity = $matter->tenant->legalEntity();

        return [
            'client_name' => $client?->full_name ?? '',
            'client_address' => $client?->address ?? '',
            'matter_reference' => $matter->reference ?? '',
            'court_name' => $matter->court_name ?? '',
            'hearing_date' => $matter->court_date?->format('d/m/Y') ?? '',
            'solicitor_name' => $matter->assignedUser?->name ?? auth()->user()->name,
            'firm_name' => (string) ($legalEntity->legal_entity_name ?? ''),
            'firm_address' => (string) ($legalEntity->firm_address ?? ''),
            'firm_phone' => (string) ($legalEntity->firm_phone ?? ''),
            'firm_email' => (string) ($legalEntity->firm_email ?? ''),
            'company_number' => (string) ($legalEntity->company_number ?? ''),
            'sra_number' => (string) ($legalEntity->sra_number ?? ''),
            'agreed_fee' => $paymentPlan ? ('£'.number_format((float) $paymentPlan->total_amount, 0)) : '',
            'date' => now()->format('d/m/Y'),
            'opponent_name' => '',
            'offer_amount' => '',
            'expert_name' => '',
            'expert_address' => '',
            'costs_to_date' => '',
            'statement_date' => now()->format('d/m/Y'),
        ];
    }

    protected function buildFilename(Matter $matter, PrecedentTemplate $template): string
    {
        $slug = Str::slug($template->template_key);

        return "{$matter->reference}-{$slug}-".now()->format('Ymd-His').'.docx';
    }
}
