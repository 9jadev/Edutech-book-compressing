<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Lessons\ChapterPlanApproved;
use App\Services\Lessons\Plan\PlanChapterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Teacher review: approve plan.json so Step 4 (Write) can start.
 *
 *   php artisan lessons:plan-approve pdfs/chapters/1/chapter-1 --by="Mrs Okafor" --note="OK, merge 01-1 and 02-1 next time"
 */
class ApprovePlanCommand extends Command
{
    protected $signature = 'lessons:plan-approve
        {folder : Chapter folder (contains plan.json)}
        {--disk= : Storage disk (default: lessons.prepare.disk)}
        {--by= : Who approved it}
        {--note= : Review note}';

    protected $description = 'Step 3: approve a chapter plan after teacher review and start the next step';

    public function handle(PlanChapterService $service): int
    {
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        try {
            $r = $service->approve($disk, (string) $this->argument('folder'), $this->option('by'), $this->option('note'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        ChapterPlanApproved::dispatch($r, []);
        $this->info("Approved {$r->planJson} ({$r->pages} pages).");
        if (!$r->passed) {
            $this->warn("Note: the plan check has {$r->errorsLeft} error(s) left (plan-check.json), so Step 4 will not start until they are fixed.");
        }
        return self::SUCCESS;
    }
}
