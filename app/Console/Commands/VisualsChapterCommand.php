<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Lessons\ChapterVisualized;
use App\Jobs\Lessons\VisualsChapterJob;
use App\Services\Lessons\Visuals\VisualsChapterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run Step 5 by hand on a written chapter folder.
 *
 *   php artisan lessons:visuals pdfs/chapters/1/chapter-1 --pages=3       (one page first)
 *   php artisan lessons:visuals pdfs/chapters/1/chapter-1                 (all pages)
 *   php artisan lessons:visuals pdfs/chapters/1/chapter-1 --preview-only  (rebuild preview pages, no AI)
 */
class VisualsChapterCommand extends Command
{
    protected $signature = 'lessons:visuals
        {folder : Chapter folder (contains plan.json and content.json)}
        {--disk= : Storage disk (default: lessons.prepare.disk)}
        {--pages= : Only these pages: positions "2,3" or part of a file name; other pages are kept}
        {--provider= : AI provider name from config/ai.php (default: lessons.visuals.provider)}
        {--model= : Model name (default: lessons.visuals.model)}
        {--force : Run even if content.json did not pass its check}
        {--preview-only : Only rebuild the preview pages from visuals.json}
        {--fire : Fire ChapterVisualized afterwards, so the next step starts}
        {--queue : Dispatch the job instead of running now}';

    protected $description = 'Step 5: describe the 3D scenes of each lesson page (visuals.json), check them, and write preview pages';

    public function handle(VisualsChapterService $service): int
    {
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        $folder = trim((string) $this->argument('folder'), '/');
        try {
            if ($this->option('preview-only')) {
                $this->info('Preview: ' . $service->preview($disk, $folder));
                return self::SUCCESS;
            }
            if ($this->option('queue')) {
                VisualsChapterJob::dispatch($disk, $folder, [], $this->option('pages'), $this->option('provider'), $this->option('model'));
                $this->info("Queued Step 5 for $folder.");
                return self::SUCCESS;
            }
            $r = $service->visuals($disk, $folder, $this->option('pages'), $this->option('provider'), $this->option('model'), fn (string $m) => $this->line("  $m"), (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $s = $r->stats;
        $this->table(['Field', 'Value'], [
            ['visuals.json', $r->visualsJson],
            ['Check passed', $r->passed ? 'yes' : ($r->errorsLeft === 0 ? 'not yet: some pages are not done (run without --pages)' : 'no (see visuals-check.json)')],
            ['Pages done', $r->pages . ' of ' . ($s['pages_total'] ?? $r->pages)],
            ['Pages right first time', ($s['pages_clean_first_time'] ?? 0) . ' / ' . $r->pages],
            ['Scenes / parts / states', ($s['scenes'] ?? 0) . ' / ' . ($s['parts'] ?? 0) . ' / ' . ($s['states'] ?? 0)],
            ['Controls / labels', ($s['controls'] ?? 0) . ' / ' . ($s['labels'] ?? 0)],
            ['Errors first pass / left', $r->errorsFirstPass . ' / ' . $r->errorsLeft],
            ['Warnings', $r->warnings],
            ['AI calls (failed)', ($r->usage['calls'] ?? 0) . ' (' . ($r->usage['failed_calls'] ?? 0) . ')'],
            ['Tokens in / out', ($r->usage['input_tokens'] ?? 0) . ' / ' . ($r->usage['output_tokens'] ?? 0)],
            ['Cost at list price', $r->usage['cost_usd'] !== null ? '$' . $r->usage['cost_usd'] : 'n/a (add the model to lessons.visuals.prices)'],
            ['Time', $r->durationSeconds . ' s'],
            ['Preview', $r->previewIndex],
        ]);
        if ($this->option('fire')) {
            ChapterVisualized::dispatch($r, []);
        }
        return $r->passed ? self::SUCCESS : self::FAILURE;
    }
}
