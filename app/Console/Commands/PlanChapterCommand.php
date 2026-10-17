<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Lessons\ChapterPlanned;
use App\Jobs\Lessons\PlanChapterJob;
use App\Services\Lessons\Plan\PageSplitter;
use App\Services\Lessons\Plan\PlanChapterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Run Step 3 by hand on a structured chapter folder.
 *
 *   php artisan lessons:plan pdfs/chapters/1/chapter-1 --pages-only    (free: just show how the pages are cut)
 *   php artisan lessons:plan pdfs/chapters/1/chapter-1                 (plan with AI)
 *   php artisan lessons:plan pdfs/chapters/1/chapter-1 --model=qwen3.5-plus
 *   php artisan lessons:plan pdfs/chapters/1/chapter-1 --queue
 */
class PlanChapterCommand extends Command
{
    protected $signature = 'lessons:plan
        {folder : Chapter folder (contains structure.json)}
        {--disk= : Storage disk (default: lessons.prepare.disk)}
        {--provider= : AI provider name from config/ai.php (default: lessons.plan.provider)}
        {--model= : Model name (default: lessons.plan.model)}
        {--pages-only : Only show the page split (no AI, no cost)}
        {--fire : Fire ChapterPlanned afterwards, so the next step starts}
        {--queue : Dispatch the job instead of running now}';

    protected $description = 'Step 3: plan the lesson pages of a chapter (plan.json) and check it covers the book';

    public function handle(PlanChapterService $service): int
    {
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        $folder = trim((string) $this->argument('folder'), '/');

        if ($this->option('pages-only')) {
            $structure = json_decode((string) Storage::disk($disk)->get("$folder/structure.json"), true);
            $cfg = (array) config('lessons.plan', []);
            $split = (new PageSplitter((int) ($cfg['min_items_per_page'] ?? 3), (int) ($cfg['max_items_per_page'] ?? 30), (bool) ($cfg['activity_own_page'] ?? true)))->split((array) $structure);
            $this->table(['Page', 'Kind', 'Title', 'Items', 'Book pages'], array_map(fn ($p) => [
                $p['key'], $p['kind'], $p['title'], $p['content_items'] . ' (' . reset($p['items']) . '–' . end($p['items']) . ')', implode(',', $p['book_pages'] ?: $p['pdf_pages']),
            ], $split['pages']));
            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            PlanChapterJob::dispatch($disk, $folder, [], $this->option('provider'), $this->option('model'));
            $this->info("Queued Step 3 for $folder.");
            return self::SUCCESS;
        }

        try {
            $r = $service->plan($disk, $folder, $this->option('provider'), $this->option('model'), fn (string $m) => $this->line("  $m"));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $s = $r->stats;
        $this->table(['Field', 'Value'], [
            ['plan.json', $r->planJson],
            ['Check passed', $r->passed ? 'yes' : 'no (see plan-check.json)'],
            ['Pages', $r->pages],
            ['Blocks', $s['blocks'] ?? 0],
            ['3D / panel visuals', ($s['visuals_3d'] ?? 0) . ' / ' . ($s['visuals_panel'] ?? 0)],
            ['Practice: book / ours', ($s['practice_book'] ?? 0) . ' / ' . ($s['practice_ours'] ?? 0)],
            ['Additions to check', $s['additions'] ?? 0],
            ['Errors left', $r->errorsLeft],
            ['Warnings', $r->warnings],
            ['Approved', $r->approved ? 'yes' : 'no — review, then: php artisan lessons:plan-approve ' . $folder],
            ['AI calls (failed)', ($r->usage['calls'] ?? 0) . ' (' . ($r->usage['failed_calls'] ?? 0) . ')'],
            ['Tokens in / out', ($r->usage['input_tokens'] ?? 0) . ' / ' . ($r->usage['output_tokens'] ?? 0)],
            ['Cost at list price', $r->usage['cost_usd'] !== null ? '$' . $r->usage['cost_usd'] : 'n/a'],
            ['Time', $r->durationSeconds . ' s'],
        ]);
        if ($this->option('fire')) {
            ChapterPlanned::dispatch($r, []);
        }
        return $r->passed ? self::SUCCESS : self::FAILURE;
    }
}
