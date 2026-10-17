<?php

declare(strict_types=1);

namespace App\Listeners\Lessons;

use App\Events\Lessons\ChapterPlanApproved;
use App\Events\Lessons\ChapterPlanned;
use Illuminate\Support\Facades\Log;

/**
 * Step 3 finished (and approved, if review is on) -> hand-off point for Step 4 (Write).
 * Listens to both events: ChapterPlanned (no review needed) and ChapterPlanApproved (after review).
 */
class StartWriteStep
{
    public function handle(ChapterPlanned|ChapterPlanApproved $event): void
    {
        $c = $event->chapter;
        if (!$c->approved) {
            Log::info('Lessons: plan waiting for teacher review', ['folder' => $c->folder, 'approve_with' => "php artisan lessons:plan-approve {$c->folder}"]);
            return;
        }
        if (!$c->passed) {
            Log::warning('Lessons: plan check did not pass; Step 4 not started', ['folder' => $c->folder, 'errors_left' => $c->errorsLeft]);
            return;
        }
        Log::info('Lessons: ready for Step 4 (Write)', [
            'folder' => $c->folder,
            'pages' => $c->pages,
            'cost_usd' => $c->usage['cost_usd'] ?? null,
            'meta' => $event->meta,
        ]);
        // When Step 4 exists: \App\Jobs\Lessons\WriteChapterJob::dispatch($c->disk, $c->folder, $event->meta);
    }
}
