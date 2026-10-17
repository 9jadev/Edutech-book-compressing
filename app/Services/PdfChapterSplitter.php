<?php

namespace App\Services;

use App\Enums\ChapterStatus;
use App\Enums\PdfDocumentStatus;
use App\Models\ChapterBound;
use App\Models\PdfDocument;
use App\Services\Lessons\LessonPipeline;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PdfChapterSplitter
{
    private const DISK = 'public';

    public function __construct(private Qpdf $qpdf, private LessonPipeline $lessons)
    {
        //
    }

    /**
     * Extract every chapter that is not done yet into its own PDF.
     *
     * The document is "running" while chapters are extracted and "done" once every chapter has
     * been attempted. Each chapter is handled on its own: a chapter that fails is marked "failed"
     * with its error in runtime_error, and the remaining chapters are still extracted.
     *
     * @param  (callable(ChapterBound): void)|null  $onChapterProcessed
     */
    public function split(PdfDocument $document, ?callable $onChapterProcessed = null): void
    {
        $chapters = $document->chapters()->where('status', '!=', ChapterStatus::Done)->get();

        if ($chapters->isEmpty()) {
            throw new RuntimeException("PDF #{$document->id} has no chapters left to extract.");
        }

        $document->update(['status' => PdfDocumentStatus::Running]);

        try {
            $sourcePath = $this->sourcePath($document);
            $pageCount = $this->qpdf->pageCount($sourcePath);
        } catch (Throwable $e) {
            // The original file cannot be read, so no chapter can be extracted.
            $sourcePath = null;
            $sourceError = $e->getMessage();
        }

        foreach ($chapters as $chapter) {
            try {
                if ($sourcePath === null) {
                    throw new RuntimeException($sourceError);
                }

                $this->assertChapterFits($chapter, $pageCount);

                $chapter->update([
                    'file_path' => $this->extractChapter($document, $chapter, $sourcePath),
                    'status' => ChapterStatus::Done,
                    'runtime_error' => null,
                ]);

                // Queue Step 1 (prepare) → Step 2 (split into lessons) for the new chapter PDF.
                $this->lessons->start($chapter);
            } catch (Throwable $e) {
                $chapter->update([
                    'status' => ChapterStatus::Failed,
                    'runtime_error' => $e->getMessage(),
                ]);
            }

            if ($onChapterProcessed) {
                $onChapterProcessed($chapter);
            }
        }

        $document->update(['status' => PdfDocumentStatus::Done]);
    }

    private function sourcePath(PdfDocument $document): string
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($document->file_path)) {
            throw new RuntimeException("Original file is missing: {$document->file_path}");
        }

        return $disk->path($document->file_path);
    }

    private function assertChapterFits(ChapterBound $chapter, int $pageCount): void
    {
        if ($chapter->start_page < 1 || $chapter->end_page < $chapter->start_page) {
            throw new RuntimeException("Invalid page range {$chapter->start_page}-{$chapter->end_page}.");
        }

        if ($chapter->end_page > $pageCount) {
            throw new RuntimeException(
                "Pages {$chapter->start_page}-{$chapter->end_page} are out of range: the PDF has {$pageCount} pages."
            );
        }
    }

    /**
     * Write the chapter's page range to a new PDF on the public disk and return its path.
     */
    private function extractChapter(PdfDocument $document, ChapterBound $chapter, string $sourcePath): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'chapter_');

        try {
            $this->qpdf->extractPages($sourcePath, $chapter->start_page, $chapter->end_page, $tempPath);

            $path = Storage::disk(self::DISK)->putFileAs(
                "pdfs/chapters/{$document->id}",
                new File($tempPath),
                "chapter-{$chapter->id}.pdf",
            );

            if ($path === false) {
                throw new RuntimeException('Could not save the chapter PDF to storage.');
            }

            return $path;
        } finally {
            @unlink($tempPath);
        }
    }
}
