<?php

namespace App\Services;

use Smalot\PdfParser\Parser;
use Throwable;

class PdfTextExtractionService
{
    /**
     * Returns null if the file can't be parsed or has no extractable text
     * layer (e.g. a scanned/image-only PDF) — OCR is out of scope for this
     * pass, so that's a clean "can't summarise this one", not an error.
     */
    public function extract(string $absolutePath): ?string
    {
        try {
            $text = trim((new Parser)->parseFile($absolutePath)->getText());
        } catch (Throwable) {
            return null;
        }

        return $text === '' ? null : $text;
    }
}
