<?php

declare(strict_types=1);

namespace App\Jobs\Lessons;

use App\Events\Lessons\ChapterPreparationFailed;
use App\Events\Lessons\ChapterPrepared;
use App\Services\Lessons\Prepare\PrepareChapterService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Step 1 — Prepare one chapter PDF (text, OCR when needed, images, pages.json).
 *
 * Dispatch it from your split job once a chapter PDF has been written:
 *   PrepareChapterJob::dispatch('local', 'books/biology/chapters/01-cell.pdf', meta: ['book_id' => 3, 'chapter' => 1]);
 *
 * When it finishes it fires ChapterPrepared, which the next step listens for.
 */
class PrepareChapterJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout;
    public array $backoff = [60];

    /**
     * @param string                $disk             storage disk name, e.g. 'local'
     * @param string                $pdfPath          chapter PDF path on that disk
     * @param ?int                  $firstPrintedPage printed number of the first page (null = detect)
     * @param ?string               $mode             auto | pdf_text | ocr (null = config default)
     * @param string[]              $headerPatterns   running header regexes for this book (empty = config default)
     * @param ?string               $ocrLang          e.g. 'eng' (null = config default)
     * @param array<string,mixed>   $meta             anything you want passed on (book_id, chapter number…)
     */
    public function __construct(
        public string $disk,
        public string $pdfPath,
        public ?int $firstPrintedPage = null,
        public ?string $mode = null,
        public array $headerPatterns = [],
        public ?string $ocrLang = null,
        public array $meta = [],
    ) {
        $this->timeout = (int) config('lessons.prepare.job_timeout', 1800);
        $this->onQueue(config('lessons.prepare.queue', 'default'));
    }

    /** One run per PDF at a time; a duplicate dispatch is dropped, not queued twice. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('prepare:' . $this->disk . ':' . $this->pdfPath))
            ->expireAfter($this->timeout + 120)
            ->dontRelease()];
    }

    public function handle(PrepareChapterService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $prepared = $service->prepare($this->disk, $this->pdfPath, [
            'first_printed_page' => $this->firstPrintedPage,
            'mode' => $this->mode,
            'header_patterns' => $this->headerPatterns,
            'ocr_lang' => $this->ocrLang,
        ]);

        ChapterPrepared::dispatch($prepared, $this->meta);
    }

    public function failed(Throwable $e): void
    {
        app(PrepareChapterService::class)->markFailed($this->disk, $this->pdfPath, $e);
        ChapterPreparationFailed::dispatch($this->disk, $this->pdfPath, $e->getMessage(), $this->meta);
    }

    public function tags(): array
    {
        return ['lessons', 'prepare', $this->pdfPath];
    }
}
