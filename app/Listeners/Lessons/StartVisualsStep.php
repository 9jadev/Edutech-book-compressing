<?php

declare(strict_types=1);

namespace App\Listeners\Lessons;

use App\Events\Lessons\ChapterWritten;
use Illuminate\Support\Facades\Log;

/** Step 4 finished -> hand-off point for Step 5 (Visuals). */
class StartVisualsStep
{
    public function handle(ChapterWritten $event): void
    {
        $c = $event->chapter;
        if (!$c->passed) {
            Log::warning('Lessons: content check did not pass; Step 5 not started', ['folder' => $c->folder, 'errors_left' => $c->errorsLeft]);
            return;
        }
        Log::info('Lessons: ready for Step 5 (Visuals)', [
            'folder' => $c->folder, 'pages' => $c->pages, 'cost_usd' => $c->usage['cost_usd'] ?? null, 'meta' => $event->meta,
        ]);
        // When Step 5 exists: \App\Jobs\Lessons\VisualsChapterJob::dispatch($c->disk, $c->folder, $event->meta);
    }
}
