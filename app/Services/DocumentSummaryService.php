<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Ollama-backed document summarisation. Uses Prism's structured() call
 * (Ollama's grammar-constrained `format` field), not a free-text prompt
 * parsed by hand — qwen3 is a reasoning model that emits <think> preamble
 * on unconstrained generation, and constrained decoding sidesteps that
 * rather than trying to strip it afterwards.
 */
class DocumentSummaryService
{
    /**
     * Defensive cap so a long document doesn't overflow Ollama's context
     * window — a rough char-based limit, not real chunking (out of scope
     * for this pass). Revisit with real document sizes.
     */
    private const MAX_CHARS = 12000;

    /**
     * @return array{summary: string, document_type: string, names: array<int, string>, dates: array<int, string>, monetary_figures: array<int, string>, ni_number: string, date_of_birth: string, phone: string, address: string, court_reference: string}
     *
     * @throws ConnectionException if Ollama is unreachable
     */
    public function summarize(string $text): array
    {
        $model = config('prism.providers.ollama.model');
        $truncated = mb_substr($text, 0, self::MAX_CHARS);

        $response = Prism::structured()
            ->using(Provider::Ollama, $model)
            ->withSchema($this->schema())
            ->withPrompt($this->prompt($truncated))
            ->withClientOptions(['connect_timeout' => 5, 'timeout' => 90])
            ->asStructured();

        return $response->structured;
    }

    private function schema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'document_summary',
            description: 'A plain-English summary and key facts extracted from a legal document.',
            properties: [
                new StringSchema('summary', 'A 3-5 sentence plain-English summary of the document.'),
                new StringSchema('document_type', 'The best-guess document type, e.g. "witness statement", "correspondence", "court order". Empty string if it cannot be identified.'),
                new ArraySchema('names', 'Full names of people or organisations mentioned in the document.', new StringSchema('name', 'A person or organisation name.')),
                new ArraySchema('dates', 'Dates mentioned in the document, written as they appear in the text.', new StringSchema('date', 'A date as it appears in the text.')),
                new ArraySchema('monetary_figures', 'Monetary amounts mentioned, written as they appear in the text (with currency symbol if present).', new StringSchema('amount', 'A monetary figure as it appears in the text.')),
                // Feature A — structured identity/case-reference candidates,
                // compared against CRM fields by DocumentFieldComparisonService.
                // Each is an empty string (never guessed/invented) if genuinely
                // not present in the text.
                new StringSchema('ni_number', "The person's National Insurance number, e.g. \"AB 12 34 56 C\", if present. Empty string if not found."),
                new StringSchema('date_of_birth', "The person's date of birth as it appears in the text, if present. Empty string if not found."),
                new StringSchema('phone', 'A phone number for the person the document concerns, if present. Empty string if not found.'),
                new StringSchema('address', 'A postal address for the person the document concerns, if present. Empty string if not found.'),
                new StringSchema('court_reference', 'A court case/claim reference number, if present (e.g. on a court order or claim form). Empty string if not found.'),
            ],
            requiredFields: ['summary', 'document_type', 'names', 'dates', 'monetary_figures', 'ni_number', 'date_of_birth', 'phone', 'address', 'court_reference'],
        );
    }

    private function prompt(string $text): string
    {
        return <<<PROMPT
            You are analysing a legal document uploaded to a case management system. Read the
            document text below and extract the requested information as accurately as possible.
            If something isn't present in the text, return an empty value rather than guessing.

            Document text:
            ---
            {$text}
            ---
            PROMPT;
    }
}
