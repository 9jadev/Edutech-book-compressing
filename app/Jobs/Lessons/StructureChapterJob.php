<?php

declare(strict_types=1);

namespace App\Jobs\Lessons;

use App\Events\Lessons\ChapterStructured;
use App\Events\Lessons\ChapterStructureFailed;
use App\Services\Lessons\Structure\StructureChapterService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Step 2 — Structure (+ 2b Check) for one prepared chapter folder.
 * Dispatched by StartStructureStep when Step 1 finishes. Fires ChapterStructured when done.
 */
class StructureChapterJob implements ShouldQueue
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
        $this->timeout = (int) config('lessons.structure.job_timeout', 1800);
        $this->onQueue(config('lessons.structure.queue', 'lessons'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('structure:' . $this->disk . ':' . $this->folder))
            ->expireAfter($this->timeout + 120)
            ->dontRelease()];
    }

    public function handle(StructureChapterService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $result = $service->structure($this->disk, $this->folder, $this->pages, $this->provider, $this->model);
        ChapterStructured::dispatch($result, $this->meta);
    }

    public function failed(Throwable $e): void
    {
        app(StructureChapterService::class)->markFailed($this->disk, $this->folder, $e);
        ChapterStructureFailed::dispatch($this->disk, $this->folder, $e->getMessage(), $this->meta);
    }

    public function tags(): array
    {
        return ['lessons', 'structure', $this->folder];
    }
}
