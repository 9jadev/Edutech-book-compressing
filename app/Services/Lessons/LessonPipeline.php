<?php

declare(strict_types=1);

namespace App\Services\Lessons;

use App\Enums\LessonsStatus;
use App\Jobs\Lessons\PrepareChapterJob;
use App\Models\ChapterBound;

/**
 * Starts the lessons pipeline for a chapter that has just been split out of its PDF:
 *
 *   PrepareChapterJob (Step 1: text, OCR, images, pages.json)
 *     → ChapterPrepared event → SplitChapterIntoLessonsJob (Step 2: lessons)
 *
 * Progress is kept on the chapter: lessons_status (preparing → splitting → done | failed) and lessons_error.
 */
class LessonPipeline
{
    public const DISK = 'public';

    public function start(ChapterBound $chapter): void
    {
        if (! $chapter->file_path) {
            return;
        }

        $chapter->update(['lessons_status' => LessonsStatus::Preparing, 'lessons_error' => null]);

        PrepareChapterJob::dispatch(
            disk: self::DISK,
            pdfPath: $chapter->file_path,
            headerPatterns: $this->headerPatterns($chapter),
            meta: ['chapter_bound_id' => $chapter->id, 'pdf_document_id' => $chapter->pdf_document_id],
        );
    }

    /**
     * Book-specific header/footer patterns plus the defaults from config/lessons.php.
     *
     * @return string[]
     */
    public function headerPatterns(ChapterBound $chapter): array
    {
        return array_values(array_unique(array_merge(
            (array) config('lessons.prepare.header_patterns', []),
            $chapter->pdfDocument?->headerRegexes() ?? [],
        )));
    }
}
