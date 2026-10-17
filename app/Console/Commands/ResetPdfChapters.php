<?php

namespace App\Console\Commands;

use App\Enums\ChapterStatus;
use App\Enums\LessonsStatus;
use App\Enums\PdfDocumentStatus;
use App\Models\ChapterBound;
use App\Models\Lesson;
use App\Models\PdfDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetPdfChapters extends Command
{
    protected $signature = 'pdfs:reset-chapters
                            {document? : ID of the PDF to reset. Omit to reset every PDF.}
                            {--force : Reset without asking for confirmation.}';

    protected $description = 'Delete the split chapter files, Step 1 output and lessons, and reset chapters and PDFs back to the start of the process';

    public function handle(): int
    {
        $id = $this->argument('document');

        if ($id !== null && ! PdfDocument::whereKey($id)->exists()) {
            $this->error("PDF #{$id} was not found.");

            return self::FAILURE;
        }

        $documents = PdfDocument::query()
            ->when($id !== null, fn ($query) => $query->whereKey($id))
            ->get();

        if ($documents->isEmpty()) {
            $this->info('No PDFs to reset.');

            return self::SUCCESS;
        }

        $chapterFiles = ChapterBound::whereIn('pdf_document_id', $documents->modelKeys())->whereNotNull('file_path')->count();
        $lessons = Lesson::whereIn('chapter_bound_id', ChapterBound::whereIn('pdf_document_id', $documents->modelKeys())->select('id'))->count();
        $running = $documents->where('status', PdfDocumentStatus::Running)->count();

        $this->line("This will reset {$documents->count()} PDF(s) and delete {$chapterFiles} split chapter file(s), their Step 1 output and {$lessons} lesson(s).");

        if ($running > 0) {
            $this->warn("{$running} of these PDF(s) are currently running. Make sure no split job is still working on them.");
        }

        if (! $this->option('force') && ! $this->confirm('Continue?')) {
            $this->info('Nothing was changed.');

            return self::SUCCESS;
        }

        foreach ($documents as $document) {
            Storage::disk('public')->deleteDirectory("pdfs/chapters/{$document->id}");

            DB::transaction(function () use ($document) {
                Lesson::whereIn('chapter_bound_id', $document->chapters()->select('id'))->delete();

                $document->chapters()->update([
                    'file_path' => null,
                    'status' => ChapterStatus::Pending,
                    'runtime_error' => null,
                    'lessons_status' => LessonsStatus::Pending,
                    'lessons_error' => null,
                    'lessons_strategy' => null,
                ]);

                $document->update(['status' => PdfDocumentStatus::Started]);
            });

            $this->line("  ✓ Reset PDF #{$document->id} ({$document->original_name})");
        }

        $this->info('Done. Run "php artisan pdfs:split-chapters" to split them again.');

        return self::SUCCESS;
    }
}
