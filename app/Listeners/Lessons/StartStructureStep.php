<?php

declare(strict_types=1);

namespace App\Listeners\Lessons;

use App\Events\Lessons\ChapterPrepared;
use App\Jobs\Lessons\SplitChapterIntoLessonsJob;
use App\Models\ChapterBound;
use Illuminate\Support\Facades\Log;

/**
 * Hand-off from Step 1 (Prepare) to Step 2 (split into lessons). Laravel finds this listener
 * automatically because handle() type-hints ChapterPrepared.
 */
class StartStructureStep
{
    public function handle(ChapterPrepared $event): void
    {
        $c = $event->chapter;

        Log::info('Lessons: ready for Step 2 (Structure)', [
            'folder' => $c->folder,
            'pages_json' => $c->pagesJson,
            'strategy' => $c->strategy,
            'warnings' => count($c->warnings),
            'meta' => $event->meta,
        ]);

        $chapter = ChapterBound::find($event->meta['chapter_bound_id'] ?? null);

        if ($chapter) {
            SplitChapterIntoLessonsJob::dispatch($chapter);
        }
    }
}
