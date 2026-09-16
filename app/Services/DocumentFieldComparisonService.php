<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Matter;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Throwable;

/**
 * Feature A — the second half of the AI document pipeline: given what
 * DocumentSummaryService extracted, decide whether anything actually needs a
 * human's attention. A clean match on every field produces no review at all
 * (needsReview() false, comparisons still recorded for transparency but
 * nothing to act on) — this only flags a genuine mismatch against an
 * existing CRM value, or a CRM field that's currently blank and the document
 * offers a value to fill it. It never writes the CRM field itself; that's a
 * deliberate human decision (see markReviewed() on DocumentAiSummary).
 *
 * court_reference compares against Matter.urn — there's no dedicated
 * "court reference" column, and urn already serves that purpose generically
 * (confirmed with the firm rather than adding a duplicate field).
 */
class DocumentFieldComparisonService
{
    /**
     * @var array<string, array{label: string, crm: \Closure(Client, Matter): (string|null), normalize: \Closure(string): (string|null)}>
     */
    private array $fields;

    public function __construct()
    {
        $this->fields = [
            'ni_number' => [
                'label' => 'NI number',
                'crm' => fn (Client $client, Matter $matter): ?string => $client->ni_number,
                'normalize' => fn (string $value): ?string => $this->normalizeCompact($value),
            ],
            'date_of_birth' => [
                'label' => 'Date of birth',
                'crm' => fn (Client $client, Matter $matter): ?string => $client->date_of_birth?->toDateString(),
                'normalize' => fn (string $value): ?string => $this->normalizeDate($value),
            ],
            'phone' => [
                'label' => 'Phone',
                'crm' => fn (Client $client, Matter $matter): ?string => $client->phone,
                'normalize' => fn (string $value): ?string => PhoneNumber::normalize($value),
            ],
            'address' => [
                'label' => 'Address',
                'crm' => fn (Client $client, Matter $matter): ?string => $client->address,
                'normalize' => fn (string $value): ?string => $this->normalizeLoose($value),
            ],
            'court_reference' => [
                'label' => 'Court reference',
                'crm' => fn (Client $client, Matter $matter): ?string => $matter->urn,
                'normalize' => fn (string $value): ?string => $this->normalizeCompact($value),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extracted  the raw extraction from DocumentSummaryService::summarize()
     * @return array{extracted_fields: array<string, string>, field_comparisons: array<string, array{label: string, extracted: string, crm: ?string, status: string}>, needs_review: bool, review_reason: ?string}
     */
    public function compare(array $extracted, Client $client, Matter $matter): array
    {
        $extractedFields = [];
        $comparisons = [];
        $reviewNotes = [];

        foreach ($this->fields as $key => $field) {
            $rawExtracted = trim((string) ($extracted[$key] ?? ''));

            if ($rawExtracted === '') {
                continue;
            }

            $extractedFields[$key] = $rawExtracted;

            $rawCrm = $field['crm']($client, $matter);
            $normalizedExtracted = $field['normalize']($rawExtracted);
            $normalizedCrm = filled($rawCrm) ? $field['normalize']((string) $rawCrm) : null;

            $status = match (true) {
                blank($normalizedCrm) => 'blank_fill',
                $normalizedCrm !== $normalizedExtracted => 'mismatch',
                default => 'match',
            };

            $comparisons[$key] = [
                'label' => $field['label'],
                'extracted' => $rawExtracted,
                'crm' => $rawCrm,
                'status' => $status,
            ];

            if ($status === 'blank_fill') {
                $reviewNotes[] = "{$field['label']} is blank on the CRM record — the document says \"{$rawExtracted}\".";
            } elseif ($status === 'mismatch') {
                $reviewNotes[] = "{$field['label']} differs from the CRM record (document: \"{$rawExtracted}\", CRM: \"{$rawCrm}\").";
            }
        }

        return [
            'extracted_fields' => $extractedFields,
            'field_comparisons' => $comparisons,
            'needs_review' => $reviewNotes !== [],
            'review_reason' => $reviewNotes === [] ? null : implode(' ', $reviewNotes),
        ];
    }

    private function normalizeCompact(string $value): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $value));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Simple, predictable heuristic (trim/case/whitespace only) — not a
     * fuzzy/semantic address match. A genuinely equivalent address written
     * differently (abbreviated county, missing postcode spacing) will show
     * as a mismatch for a human to eyeball rather than silently auto-match;
     * documented here rather than promising more than this actually does.
     */
    private function normalizeLoose(string $value): ?string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $value)));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Best-effort parse of whatever date format the document/AI produced.
     * Numeric d/m/y-shaped dates are tried as UK day-first *before* falling
     * back to Carbon's generic parse — Carbon (via PHP's strtotime) assumes
     * US month-first for slash-separated dates, which would silently
     * misread a UK document's "12/03/1985" as 3 December instead of
     * 12 March, turning a genuine match into a false mismatch. Found via a
     * real test, not by inspection. Falls back to the compact-normalized raw
     * string (still a fair same-vs-different comparison) if nothing parses.
     */
    private function normalizeDate(string $value): ?string
    {
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);

                if ($parsed !== false) {
                    return $parsed->toDateString();
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (InvalidFormatException|Throwable) {
            return $this->normalizeCompact($value);
        }
    }
}
