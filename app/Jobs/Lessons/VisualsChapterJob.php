<?php

declare(strict_types=1);

namespace App\Jobs\Lessons;

use App\Events\Lessons\ChapterVisualized;
use App\Events\Lessons\ChapterVisualsFailed;
use App\Services\Lessons\Visuals\VisualsChapterService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Step 5 — Visuals for one written chapter folder. Fires ChapterVisualized when done. */
class VisualsChapterJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout;
    public array $backoff = [120];

    public function __construct(public string $disk, public string $folder, public array $meta = [], public ?string $pages = null, public ?string $provider = null, public ?string $model = null)
    {
        $this->timeout = (int) config('lessons.visuals.job_timeout', 3600);
        $this->onQueue(config('lessons.visuals.queue', 'lessons'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('visuals:' . $this->disk . ':' . $this->folder))->expireAfter($this->timeout + 120)->dontRelease()];
    }

    public function handle(VisualsChapterService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        ChapterVisualized::dispatch($service->visuals($this->disk, $this->folder, $this->pages, $this->provider, $this->model), $this->meta);
    }

    public function failed(Throwable $e): void
    {
        app(VisualsChapterService::class)->markFailed($this->disk, $this->folder, $e);
        ChapterVisualsFailed::dispatch($this->disk, $this->folder, $e->getMessage(), $this->meta);
    }

    public function tags(): array
    {
        return ['lessons', 'visuals', $this->folder];
    }
}
