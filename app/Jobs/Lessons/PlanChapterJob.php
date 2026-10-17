<?php

declare(strict_types=1);

namespace App\Jobs\Lessons;

use App\Events\Lessons\ChapterPlanFailed;
use App\Events\Lessons\ChapterPlanned;
use App\Services\Lessons\Plan\PlanChapterService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Step 3 — Plan the lesson pages of one structured chapter folder (plan.json).
 * Dispatched by StartPlanStep when Step 2 finishes, or by lessons:plan --queue. Fires ChapterPlanned when done.
 */
class PlanChapterJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout;
    public array $backoff = [120];

    public function __construct(
        public string $disk,
        public string $folder,
        public array $meta = [],
        public ?string $provider = null,
        public ?string $model = null,
    ) {
        $this->timeout = (int) config('lessons.plan.job_timeout', 1800);
        $this->onQueue(config('lessons.plan.queue', 'lessons'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('plan:' . $this->disk . ':' . $this->folder))
            ->expireAfter($this->timeout + 120)
            ->dontRelease()];
    }

    public function handle(PlanChapterService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $result = $service->plan($this->disk, $this->folder, $this->provider, $this->model);
        ChapterPlanned::dispatch($result, $this->meta);
    }

    public function failed(Throwable $e): void
    {
        app(PlanChapterService::class)->markFailed($this->disk, $this->folder, $e);
        ChapterPlanFailed::dispatch($this->disk, $this->folder, $e->getMessage(), $this->meta);
    }

    public function tags(): array
    {
        return ['lessons', 'plan', $this->folder];
    }
}
