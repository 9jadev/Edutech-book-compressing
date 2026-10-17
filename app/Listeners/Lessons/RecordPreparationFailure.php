<?php

declare(strict_types=1);

namespace App\Listeners\Lessons;

use App\Enums\LessonsStatus;
use App\Events\Lessons\ChapterPreparationFailed;
use App\Models\ChapterBound;

/**
 * Step 1 gave up after its retries: save the error on the chapter so it shows on the library page.
 */
class RecordPreparationFailure
{
    public function handle(ChapterPreparationFailed $event): void
    {
        ChapterBound::whereKey($event->meta['chapter_bound_id'] ?? null)->update([
            'lessons_status' => LessonsStatus::Failed,
            'lessons_error' => 'Step 1 (prepare) failed: '.$event->error,
        ]);
    }
}
