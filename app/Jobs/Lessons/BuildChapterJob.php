<?php

declare(strict_types=1);

namespace App\Jobs\Lessons;

use App\Events\Lessons\ChapterBuildFailed;
use App\Events\Lessons\ChapterBuilt;
use App\Services\Lessons\Build\BuildChapterService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Step 6 — Build the lesson pages of one chapter folder. Fires ChapterBuilt when done. */
class BuildChapterJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(public string $disk, public string $folder, public array $meta = [])
    {
        $this->onQueue(config('lessons.build.queue', 'lessons'));
    }

    public function handle(BuildChapterService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        ChapterBuilt::dispatch($service->build($this->disk, $this->folder), $this->meta);
    }

    public function failed(Throwable $e): void
    {
        app(BuildChapterService::class)->markFailed($this->disk, $this->folder, $e);
        ChapterBuildFailed::dispatch($this->disk, $this->folder, $e->getMessage(), $this->meta);
    }

    public function tags(): array
    {
        return ['lessons', 'build', $this->folder];
    }
}
