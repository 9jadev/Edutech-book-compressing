<?php

declare(strict_types=1);

namespace App\Jobs\Lessons;

use App\Events\Lessons\ChapterWriteFailed;
use App\Events\Lessons\ChapterWritten;
use App\Services\Lessons\Write\WriteChapterService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Step 4 — Write each lesson page of one planned chapter folder (notes, narration, practice) and check it.
 * Dispatched by StartWriteStep when the plan is ready (or approved), or by lessons:write --queue. Fires ChapterWritten when done.
 */
class WriteChapterJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout;
    public array $backoff = [120];

    public function __construct(
        public string $disk,
        public string $folder,
        public array $meta = [],
        public ?string $pages = null,
        public ?string $provider = null,
        public ?string $model = null,
    ) {
        $this->timeout = (int) config('lessons.write.job_timeout', 1800);
        $this->onQueue(config('lessons.write.queue', 'lessons'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('write:' . $this->disk . ':' . $this->folder))
            ->expireAfter($this->timeout + 120)
            ->dontRelease()];
    }

    public function handle(WriteChapterService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $result = $service->write($this->disk, $this->folder, $this->pages, $this->provider, $this->model);
        ChapterWritten::dispatch($result, $this->meta);
    }

    public function failed(Throwable $e): void
    {
        app(WriteChapterService::class)->markFailed($this->disk, $this->folder, $e);
        ChapterWriteFailed::dispatch($this->disk, $this->folder, $e->getMessage(), $this->meta);
    }

    public function tags(): array
    {
        return ['lessons', 'write', $this->folder];
    }
}
