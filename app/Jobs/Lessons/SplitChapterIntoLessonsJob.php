<?php

declare(strict_types=1);

namespace App\Jobs\Lessons;

use App\Enums\LessonsStatus;
use App\Models\ChapterBound;
use App\Services\Lessons\Structure\LessonSplitter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 2 — split one prepared chapter into lessons. Dispatched when Step 1 fires ChapterPrepared.
 */
class SplitChapterIntoLessonsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** The splitter records its own failure on the chapter, so a retry would only repeat it. */
    public int $tries = 1;

    public int $timeout;

    public function __construct(public ChapterBound $chapter)
    {
        $this->timeout = (int) config('lessons.structure.job_timeout', 600);
        $this->onQueue(config('lessons.prepare.queue', 'lessons'));
    }

    /** Only one lesson split per chapter can be queued or running at a time. */
    public function uniqueId(): string
    {
        return (string) $this->chapter->id;
    }

    public function handle(LessonSplitter $splitter): void
    {
        $plan = $splitter->split($this->chapter);

        Log::info('Lessons: chapter split into lessons', [
            'chapter_bound_id' => $this->chapter->id,
            'strategy' => $plan['strategy'],
            'lessons' => array_column($plan['lessons'], 'title'),
        ]);
    }

    /**
     * The job crashed or timed out before the splitter could record it: don't leave the chapter "splitting".
     */
    public function failed(?Throwable $exception): void
    {
        $chapter = $this->chapter->fresh();

        if ($chapter && $chapter->lessons_status !== LessonsStatus::Failed) {
            $chapter->update([
                'lessons_status' => LessonsStatus::Failed,
                'lessons_error' => 'Lesson split job failed: '.($exception?->getMessage() ?: 'unknown error'),
            ]);
        }
    }

    public function tags(): array
    {
        return ['lessons', 'structure', 'chapter:'.$this->chapter->id];
    }
}
