<?php

namespace App\Console\Commands;

use App\Enums\ChapterStatus;
use App\Enums\LessonsStatus;
use App\Jobs\Lessons\SplitChapterIntoLessonsJob;
use App\Models\ChapterBound;
use App\Services\Lessons\LessonPipeline;
use App\Services\Lessons\Prepare\PrepareChapterService;
use App\Services\Lessons\Structure\LessonSplitter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SplitChapterLessons extends Command
{
    protected $signature = 'pdfs:split-lessons
                            {chapter? : ID of the chapter to split into lessons.}
                            {--document= : Split every chapter of this PDF.}
                            {--prepare : Re-run Step 1 (text, OCR, images) even if it already ran.}
                            {--queue : Queue the jobs instead of running now in this terminal.}
                            {--dry-run : Only show the lessons that would be created; save nothing.}';

    protected $description = 'Manually (re)run the lessons pipeline for chapters. New chapters are queued automatically after the PDF split.';

    public function handle(LessonSplitter $splitter, LessonPipeline $pipeline, PrepareChapterService $prepare): int
    {
        $chapters = $this->chapters();

        if ($chapters->isEmpty()) {
            $this->info('No chapters to split into lessons. Chapters need to be split out of their PDF first.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($chapters as $chapter) {
            $label = "Chapter #{$chapter->id} \"{$chapter->chapter_name}\" (PDF #{$chapter->pdf_document_id})";
            $needsPrepare = $this->option('prepare') || ! $this->isPrepared($chapter);

            if ($this->option('queue')) {
                $needsPrepare ? $pipeline->start($chapter) : SplitChapterIntoLessonsJob::dispatch($chapter);
                $this->line("Queued {$label}".($needsPrepare ? ' (Step 1 + Step 2)' : ' (Step 2)').'.');

                continue;
            }

            $this->line("{$label}...");

            try {
                if ($this->option('dry-run')) {
                    if ($needsPrepare) {
                        throw new \RuntimeException('Step 1 has not run for this chapter yet; a dry run cannot show lessons without it.');
                    }
                    $this->table(...$this->rows($splitter->plan($chapter)));

                    continue;
                }

                if ($needsPrepare) {
                    $this->line('  Step 1: preparing text, OCR and images...');
                    $chapter->update(['lessons_status' => LessonsStatus::Preparing, 'lessons_error' => null]);
                    $prepared = $prepare->prepare(LessonPipeline::DISK, $chapter->file_path, ['header_patterns' => $pipeline->headerPatterns($chapter)]);
                    $this->line("  Step 1 done: {$prepared->pageCount} pages, strategy {$prepared->strategy}, ".count($prepared->warnings).' warning(s).');
                }

                $this->line('  Step 2: splitting into lessons...');
                $this->table(...$this->rows($splitter->split($chapter)));
            } catch (Throwable $e) {
                $failed++;
                if ($chapter->fresh()->lessons_status !== LessonsStatus::Failed && ! $this->option('dry-run')) {
                    $chapter->update(['lessons_status' => LessonsStatus::Failed, 'lessons_error' => $e->getMessage()]);
                }
                $this->error("  ✗ {$e->getMessage()}");
            }
        }

        if ($this->option('queue')) {
            $this->info('Run "php artisan queue:work --queue=lessons" if no lessons worker is running.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, ChapterBound>
     */
    private function chapters(): Collection
    {
        $query = ChapterBound::with('pdfDocument')->where('status', ChapterStatus::Done)->orderBy('id');

        if ($id = $this->argument('chapter')) {
            return $query->whereKey($id)->get();
        }
        if ($document = $this->option('document')) {
            return $query->where('pdf_document_id', $document)->get();
        }

        return $query->where('lessons_status', '!=', LessonsStatus::Done)->get();
    }

    private function isPrepared(ChapterBound $chapter): bool
    {
        return Storage::disk(LessonPipeline::DISK)->exists(PrepareChapterService::folderFor($chapter->file_path).'/pages.json');
    }

    /**
     * @param  array{strategy: string, lessons: array<int, array<string, mixed>>}  $plan
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function rows(array $plan): array
    {
        $this->line("  Headings found by: {$plan['strategy']}");

        return [
            ['#', 'Lesson', 'Pages', 'Printed', 'Chars', 'Warnings'],
            array_map(fn ($l) => [
                $l['position'],
                mb_strimwidth($l['title'], 0, 60, '…'),
                "{$l['start_page']}-{$l['end_page']}",
                $l['printed_start_page'] ? "{$l['printed_start_page']}-{$l['printed_end_page']}" : '-',
                mb_strlen((string) $l['content']),
                count($l['warnings'] ?? []),
            ], $plan['lessons']),
        ];
    }
}
