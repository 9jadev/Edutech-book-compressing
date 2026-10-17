<?php

declare(strict_types=1);

namespace App\Listeners\Lessons;

use App\Events\Lessons\ChapterStructured;
use Illuminate\Support\Facades\Log;

/** Step 2 finished -> hand-off point for Step 3 (Plan). */
class StartPlanStep
{
    public function handle(ChapterStructured $event): void
    {
        $c = $event->chapter;
        Log::info('Lessons: ready for Step 3 (Plan)', [
            'folder' => $c->folder,
            'passed' => $c->passed,
            'items' => $c->items,
            'errors_left' => $c->errorsLeft,
            'cost_usd' => $c->usage['cost_usd'] ?? null,
            'meta' => $event->meta,
        ]);
        // When Step 3 exists: \App\Jobs\Lessons\PlanChapterJob::dispatch($c->disk, $c->folder, $event->meta);
    }
}
