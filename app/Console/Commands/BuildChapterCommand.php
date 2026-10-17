<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Lessons\ChapterBuilt;
use App\Jobs\Lessons\BuildChapterJob;
use App\Services\Lessons\Build\BuildChapterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run Step 6 by hand: build the lesson pages of a chapter.
 *
 *   php artisan lessons:build pdfs/chapters/1/chapter-1
 */
class BuildChapterCommand extends Command
{
    protected $signature = 'lessons:build
        {folder : Chapter folder (contains plan.json, content.json, visuals.json)}
        {--disk= : Storage disk (default: lessons.prepare.disk)}
        {--force : Build even if content.json or visuals.json did not pass their checks}
        {--fire : Fire ChapterBuilt afterwards, so the next step starts}
        {--queue : Dispatch the job instead of running now}';

    protected $description = 'Step 6: build the narrated 3D lesson pages (build/) from content.json and visuals.json';

    public function handle(BuildChapterService $service): int
    {
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        $folder = trim((string) $this->argument('folder'), '/');
        if ($this->option('queue')) {
            BuildChapterJob::dispatch($disk, $folder);
            $this->info("Queued Step 6 for $folder.");
            return self::SUCCESS;
        }
        try {
            $r = $service->build($disk, $folder, (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->table(['Field', 'Value'], [
            ['Built into', $r->buildDir],
            ['Check passed', $r->passed ? 'yes' : 'no (see build-check.json)'],
            ['Pages', $r->pages . ' of ' . $r->pagesPlanned],
            ['3D scenes', $r->scenes],
            ['Questions', $r->questions],
            ['Errors / warnings', count($r->errors) . ' / ' . count($r->warnings)],
            ['Time', $r->durationSeconds . ' s'],
            ['Open', $r->index],
        ]);
        foreach ($r->errors as $e) {
            $this->error($e);
        }
        foreach (array_slice($r->warnings, 0, 10) as $w) {
            $this->warn($w);
        }
        if ($this->option('fire')) {
            ChapterBuilt::dispatch($r, []);
        }
        return $r->passed ? self::SUCCESS : self::FAILURE;
    }
}
