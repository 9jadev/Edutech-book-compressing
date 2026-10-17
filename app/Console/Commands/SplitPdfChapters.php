<?php

namespace App\Console\Commands;

use App\Enums\ChapterStatus;
use App\Enums\PdfDocumentStatus;
use App\Jobs\SplitPdfDocumentChapters;
use App\Models\ChapterBound;
use App\Models\PdfDocument;
use App\Services\PdfChapterSplitter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class SplitPdfChapters extends Command
{
    protected $signature = 'pdfs:split-chapters
                            {document? : ID of the PDF to split (only its chapters that are not done yet). Omit to split every PDF whose status is "started".}
                            {--queue : Queue a background job per PDF instead of splitting now in this terminal.}
                            {--force : Split the PDF even if it is already marked "running".}';

    protected $description = 'Manually (re)run the chapter split for a PDF. Uploads already queue this automatically.';

    public function handle(PdfChapterSplitter $splitter): int
    {
        $documents = $this->documents();

        if ($documents === null) {
            return self::FAILURE;
        }

        if ($documents->isEmpty()) {
            $this->info('No PDFs to split.');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            foreach ($documents as $document) {
                SplitPdfDocumentChapters::dispatch($document);
                $this->line("Queued PDF #{$document->id} ({$document->original_name}).");
            }

            $this->info('Run "php artisan queue:work" to process the queue if no worker is running.');

            return self::SUCCESS;
        }

        $failedChapters = 0;

        foreach ($documents as $document) {
            $this->line("Splitting PDF #{$document->id} ({$document->original_name})...");

            try {
                $splitter->split($document, function (ChapterBound $chapter) use (&$failedChapters) {
                    $label = "{$chapter->chapter_name} (pages {$chapter->start_page}-{$chapter->end_page})";

                    if ($chapter->status === ChapterStatus::Done) {
                        $this->line("  ✓ {$label} → {$chapter->file_path}");
                    } else {
                        $failedChapters++;
                        $this->error("  ✗ {$label}: {$chapter->runtime_error}");
                    }
                });

                $this->info("PDF #{$document->id} is done.");
            } catch (Throwable $e) {
                $this->warn("PDF #{$document->id} skipped: {$e->getMessage()}");
            }
        }

        if ($failedChapters > 0) {
            $this->error("{$failedChapters} chapter(s) failed. Their errors are saved in the runtime_error column.");
        }

        return $failedChapters === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, PdfDocument>|null
     */
    private function documents(): ?Collection
    {
        $id = $this->argument('document');

        if ($id === null) {
            return PdfDocument::where('status', PdfDocumentStatus::Started)->get();
        }

        $document = PdfDocument::find($id);

        if (! $document) {
            $this->error("PDF #{$id} was not found.");

            return null;
        }

        if ($document->status === PdfDocumentStatus::Running && ! $this->option('force')) {
            $this->error("PDF #{$id} is already running. Use --force if a previous run was interrupted.");

            return null;
        }

        return collect([$document]);
    }
}
