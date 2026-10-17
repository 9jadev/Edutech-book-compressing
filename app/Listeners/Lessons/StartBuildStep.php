<?php

declare(strict_types=1);

namespace App\Listeners\Lessons;

use App\Events\Lessons\ChapterVisualized;
use App\Jobs\Lessons\BuildChapterJob;
use Illuminate\Support\Facades\Log;

/**
 * Step 5 finished -> start Step 6. Replaces the Step 5 placeholder of the same name.
 * Set LESSONS_BUILD_AUTO=false to stop the automatic hand-off (run lessons:build by hand).
 */
class StartBuildStep
{
    public function handle(ChapterVisualized $event): void
    {
        $c = $event->chapter;
        if (!$c->passed) {
            Log::warning('Lessons: visuals check did not pass; Step 6 not started', ['folder' => $c->folder, 'errors_left' => $c->errorsLeft]);
            return;
        }
        if (!config('lessons.build.auto_start', true)) {
            Log::info('Lessons: Step 6 auto-start is off', ['folder' => $c->folder]);
            return;
        }
        BuildChapterJob::dispatch($c->disk, $c->folder, $event->meta);
    }
}
