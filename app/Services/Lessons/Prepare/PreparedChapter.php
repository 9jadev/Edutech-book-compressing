<?php

declare(strict_types=1);

namespace App\Services\Lessons\Prepare;

/**
 * What Step 1 produced. Passed to the ChapterPrepared event so the next step knows where to look.
 * All paths are relative to the storage disk.
 */
final class PreparedChapter
{
    public function __construct(
        public readonly string $disk,
        public readonly string $sourcePdf,      // the chapter PDF you gave it, e.g. books/biology/chapters/01-cell.pdf
        public readonly string $folder,         // books/biology/chapters/01-cell
        public readonly string $pdf,            // books/biology/chapters/01-cell/01-cell.pdf
        public readonly string $pagesJson,      // books/biology/chapters/01-cell/pages.json
        public readonly string $strategy,       // pdf_text | ocr_all
        public readonly int $pageCount,
        public readonly ?int $firstPrintedPage,
        public readonly array $totals,
        public readonly array $warnings,
        public readonly float $durationSeconds,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
