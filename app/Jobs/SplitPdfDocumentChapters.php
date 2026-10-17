<?php

namespace App\Jobs;

use App\Enums\ChapterStatus;
use App\Enums\PdfDocumentStatus;
use App\Models\PdfDocument;
use App\Services\PdfChapterSplitter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SplitPdfDocumentChapters implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Chapters already record their own failures, so retrying the whole job adds nothing.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public PdfDocument $document)
    {
        //
    }

    /**
     * Only one split job per PDF can be queued or running at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->document->id;
    }

    public function handle(PdfChapterSplitter $splitter): void
    {
        if ($this->document->chapters()->where('status', '!=', ChapterStatus::Done)->doesntExist()) {
            return;
        }

        $splitter->split($this->document);
    }

    /**
     * The job crashed or timed out: record why on every chapter that did not finish,
     * so the PDF is not left stuck in "running".
     */
    public function failed(?Throwable $exception): void
    {
        $this->document->chapters()
            ->where('status', ChapterStatus::Pending)
            ->update([
                'status' => ChapterStatus::Failed,
                'runtime_error' => 'Split job failed: '.($exception?->getMessage() ?: 'unknown error'),
            ]);

        $this->document->update(['status' => PdfDocumentStatus::Done]);
    }
}
