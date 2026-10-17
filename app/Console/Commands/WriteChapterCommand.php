<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Lessons\ChapterWritten;
use App\Jobs\Lessons\WriteChapterJob;
use App\Services\Lessons\Write\WriteChapterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run Step 4 by hand on a planned chapter folder.
 *
 *   php artisan lessons:write pdfs/chapters/1/chapter-1 --pages=2          (one page, cheap test)
 *   php artisan lessons:write pdfs/chapters/1/chapter-1                    (all pages)
 *   php artisan lessons:write pdfs/chapters/1/chapter-1 --pages=osmosis    (pages whose name contains "osmosis")
 *   php artisan lessons:write pdfs/chapters/1/chapter-1 --queue
 */
class WriteChapterCommand extends Command
{
    protected $signature = 'lessons:write
        {folder : Chapter folder (contains plan.json)}
        {--disk= : Storage disk (default: lessons.prepare.disk)}
        {--pages= : Only these pages: positions "2,3" or part of a file name; other pages are kept}
        {--provider= : AI provider name from config/ai.php (default: lessons.write.provider)}
        {--model= : Model name (default: lessons.write.model)}
        {--force : Write even if the plan is not approved or did not pass its check}
        {--fire : Fire ChapterWritten afterwards, so the next step starts}
        {--queue : Dispatch the job instead of running now}';

    protected $description = 'Step 4: write each lesson page (notes, narration, practice) and check it';

    public function handle(WriteChapterService $service): int
    {
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        $folder = trim((string) $this->argument('folder'), '/');

        if ($this->option('queue')) {
            WriteChapterJob::dispatch($disk, $folder, [], $this->option('pages'), $this->option('provider'), $this->option('model'));
            $this->info("Queued Step 4 for $folder.");
            return self::SUCCESS;
        }

        try {
            $r = $service->write($disk, $folder, $this->option('pages'), $this->option('provider'), $this->option('model'),
                fn (string $m) => $this->line("  $m"), (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $s = $r->stats;
        $this->table(['Field', 'Value'], [
            ['content.json', $r->contentJson],
            ['Check passed', $r->passed ? 'yes' : ($r->errorsLeft === 0 ? 'not yet: some pages are not done (run without --pages)' : 'no (see content-check.json)')],
            ['Pages done', $r->pages . ' of ' . ($s['pages_total'] ?? $r->pages)],
            ['Pages', $r->pages],
            ['Paragraphs / steps / tasks', ($s['paragraphs'] ?? 0) . ' / ' . ($s['steps'] ?? 0) . ' / ' . ($s['tasks'] ?? 0)],
            ['Questions: book / ours', ($s['questions_book'] ?? 0) . ' / ' . ($s['questions_ours'] ?? 0)],
            ['Errors first pass / left', $r->errorsFirstPass . ' / ' . $r->errorsLeft],
            ['Warnings', $r->warnings],
            ['AI calls (failed)', ($r->usage['calls'] ?? 0) . ' (' . ($r->usage['failed_calls'] ?? 0) . ')'],
            ['Tokens in / out', ($r->usage['input_tokens'] ?? 0) . ' / ' . ($r->usage['output_tokens'] ?? 0)],
            ['Cost at list price', $r->usage['cost_usd'] !== null ? '$' . $r->usage['cost_usd'] : 'n/a (add the model to lessons.write.prices)'],
            ['Time', $r->durationSeconds . ' s'],
        ]);
        if ($this->option('fire')) {
            ChapterWritten::dispatch($r, []);
        }
        return $r->passed ? self::SUCCESS : self::FAILURE;
    }
}
