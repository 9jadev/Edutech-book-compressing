<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Lessons\StructureChapterJob;
use App\Services\Lessons\Structure\StructureChapterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run Step 2 by hand on a prepared chapter folder.
 *
 *   php artisan lessons:structure pdfs/chapters/1/chapter-1 --pages=1-3     (cheap test on 3 pages)
 *   php artisan lessons:structure pdfs/chapters/1/chapter-1                 (whole chapter)
 *   php artisan lessons:structure pdfs/chapters/1/chapter-1 --model=qwen3.6-plus
 *   php artisan lessons:structure pdfs/chapters/1/chapter-1 --queue
 */
class StructureChapterCommand extends Command
{
    protected $signature = 'lessons:structure
        {folder : Chapter folder made by Step 1 (contains pages.json)}
        {--disk= : Storage disk (default: lessons.prepare.disk)}
        {--pages= : Only these pages, e.g. 1-3 or 1,4}
        {--provider= : AI provider name from config/ai.php (default: lessons.structure.provider)}
        {--model= : Model name (default: lessons.structure.model)}
        {--queue : Dispatch the job instead of running now}';

    protected $description = 'Step 2: list everything in a chapter (structure.json) and check it against the page text';

    public function handle(StructureChapterService $service): int
    {
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        $folder = (string) $this->argument('folder');

        if ($this->option('queue')) {
            StructureChapterJob::dispatch($disk, $folder, [], $this->option('pages'), $this->option('provider'), $this->option('model'));
            $this->info("Queued Step 2 for $folder.");
            return self::SUCCESS;
        }

        try {
            $r = $service->structure($disk, $folder, $this->option('pages'), $this->option('provider'), $this->option('model'),
                fn (string $m) => $this->line("  $m"));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['structure.json', $r->structureJson],
            ['Check passed', $r->passed ? 'yes' : 'no (see structure-check.json)'],
            ['Items', $r->items],
            ['Errors left', $r->errorsLeft],
            ['Warnings', $r->warnings],
            ['Figures / tables', ($r->stats['figures'] ?? 0) . ' / ' . ($r->stats['tables'] ?? 0)],
            ['Examples / parts', ($r->stats['examples'] ?? 0) . ' / ' . ($r->stats['example_parts'] ?? 0)],
            ['Exercises / questions / parts', ($r->stats['exercises'] ?? 0) . ' / ' . ($r->stats['questions'] ?? 0) . ' / ' . ($r->stats['question_parts'] ?? 0)],
            ['AI calls (failed)', ($r->usage['calls'] ?? 0) . ' (' . ($r->usage['failed_calls'] ?? 0) . ')'],
            ['Tokens in / out', ($r->usage['input_tokens'] ?? 0) . ' / ' . ($r->usage['output_tokens'] ?? 0)],
            ['Cost at list price', $r->usage['cost_usd'] !== null ? '$' . $r->usage['cost_usd'] : 'n/a'],
            ['Time', $r->durationSeconds . ' s'],
        ]);
        return $r->passed ? self::SUCCESS : self::FAILURE;
    }
}
