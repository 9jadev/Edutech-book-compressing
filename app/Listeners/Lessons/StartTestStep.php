<?php

declare(strict_types=1);

namespace App\Listeners\Lessons;

use App\Events\Lessons\ChapterBuilt;
use Illuminate\Support\Facades\Log;

/** Step 6 finished -> hand-off point for Step 7 (Test). */
class StartTestStep
{
    public function handle(ChapterBuilt $event): void
    {
        $c = $event->chapter;
        Log::info('Lessons: ready for Step 7 (Test)', ['folder' => $c->folder, 'index' => $c->index, 'passed' => $c->passed, 'meta' => $event->meta]);
        // When Step 7 exists: \App\Jobs\Lessons\TestChapterJob::dispatch($c->disk, $c->folder, $event->meta);
    }
}
